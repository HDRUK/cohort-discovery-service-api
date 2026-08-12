<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Sso\ProviderNotConfiguredException;
use App\Exceptions\Sso\SsoException;
use App\Http\Controllers\Controller;
use App\Services\Authentication\LocalPersonalAccessTokenService;
use App\Services\Sso\OidcClient;
use App\Services\Sso\OidcProviderConfig;
use App\Services\Sso\OneTimeCodeStore;
use App\Services\Sso\SsoUserResolver;
use App\Support\ApplicationMode;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * External OIDC single sign-on - the alternative front door for standalone mode.
 *
 * The flow:
 *   1. FE asks /providers what's on offer
 *   2. Browser hits /{provider}/redirect and is bounced to the IdP
 *   3. IdP bounces back to /{provider}/callback - we validate, mint the same
 *      RS256 JWT a password login would get, and bounce the browser to the FE
 *      with a single-use code
 *   4. FE swaps the code at /exchange for the actual token
 *
 * Three bounces, one token, and at no point does the token itself ride in a URL.
 * In integrated mode the Gateway is the IdP, so all of this 404s out of politeness.
 *
 * @OA\Tag(
 *     name="SSO",
 *     description="External OIDC single sign-on (standalone mode only)"
 * )
 */
class SsoController extends Controller
{
    use Responses;

    public function __construct(
        private readonly OidcClient $oidc,
        private readonly SsoUserResolver $resolver,
        private readonly OneTimeCodeStore $codes,
        private readonly LocalPersonalAccessTokenService $tokens,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/auth/sso/providers",
     *     summary="List enabled SSO providers",
     *     tags={"SSO"},
     *     @OA\Response(
     *         response=200,
     *         description="Enabled providers with their login redirect URLs",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="slug", type="string", example="default"),
     *                 @OA\Property(property="label", type="string", example="Single Sign-On"),
     *                 @OA\Property(property="redirect_url", type="string", example="https://api.example.com/api/auth/sso/default/redirect")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=404, description="SSO disabled or integrated mode")
     * )
     */
    public function providers(): JsonResponse
    {
        $this->ensureSsoAvailable();

        $providers = collect(OidcProviderConfig::enabledProviders())
            ->map(fn (string $label, string $slug) => [
                'slug' => $slug,
                'label' => $label,
                'redirect_url' => url("/api/auth/sso/{$slug}/redirect"),
            ])
            ->values()
            ->all();

        return $this->OKResponse($providers);
    }

    /**
     * @OA\Get(
     *     path="/api/auth/sso/{provider}/redirect",
     *     summary="Begin an SSO login (browser navigation, 302 to the IdP)",
     *     tags={"SSO"},
     *     @OA\Parameter(name="provider", in="path", required=true, @OA\Schema(type="string", example="default")),
     *     @OA\Response(response=302, description="Redirect to the identity provider"),
     *     @OA\Response(response=404, description="Unknown or disabled provider")
     * )
     */
    public function redirect(string $provider): RedirectResponse
    {
        $this->ensureSsoAvailable();

        try {
            $config = OidcProviderConfig::fromConfig($provider);
        } catch (ProviderNotConfiguredException) {
            abort(404);
        }

        return redirect()->away($this->oidc->buildAuthorizationRedirect($config));
    }

    /**
     * @OA\Get(
     *     path="/api/auth/sso/{provider}/callback",
     *     summary="OIDC redirect URI (browser navigation, 302 back to the frontend)",
     *     tags={"SSO"},
     *     @OA\Parameter(name="provider", in="path", required=true, @OA\Schema(type="string", example="default")),
     *     @OA\Parameter(name="code", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="state", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Response(response=302, description="Redirect to the frontend with a one-time handoff code, or to the error URL"),
     *     @OA\Response(response=404, description="Unknown or disabled provider")
     * )
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->ensureSsoAvailable();

        try {
            $config = OidcProviderConfig::fromConfig($provider);
        } catch (ProviderNotConfiguredException) {
            abort(404);
        }

        if ($request->query('error')) {
            \Log::warning('SSO login rejected by IdP', [
                'provider' => $provider,
                'error' => $request->query('error'),
            ]);

            return $this->errorRedirect('idp_error');
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (! is_string($code) || ! is_string($state) || $code === '' || $state === '') {
            return $this->errorRedirect('invalid_callback');
        }

        try {
            $result = $this->oidc->handleCallback($config, $code, $state);
            $user = $this->resolver->resolve($config, $result);
        } catch (SsoException $e) {
            \Log::warning('SSO login failed', [
                'provider' => $provider,
                'error_code' => $e->errorCode,
                'detail' => $e->getMessage(),
            ]);

            return $this->errorRedirect($e->errorCode);
        }

        $token = $this->tokens->makeForUser($user, 'sso_login');
        $handoffCode = $this->codes->issue($token->accessToken);

        \Log::info('SSO login succeeded', [
            'provider' => $provider,
            'user_id' => $user->id,
        ]);

        $callbackUrl = config('sso.frontend_callback_url');

        return redirect()->away(
            $callbackUrl.(str_contains($callbackUrl, '?') ? '&' : '?').http_build_query([
                'code' => $handoffCode,
                'provider' => $provider,
            ])
        );
    }

    /**
     * @OA\Post(
     *     path="/api/auth/sso/exchange",
     *     summary="Exchange a one-time handoff code for an access token",
     *     tags={"SSO"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"code"},
     *             @OA\Property(property="code", type="string", example="9f86d081884c7d65...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Authenticated",
     *         @OA\JsonContent(
     *             @OA\Property(property="data",
     *                 @OA\Property(property="message", type="string", example="authenticated"),
     *                 @OA\Property(property="access_token", type="string"),
     *                 @OA\Property(property="token_type", type="string", example="Bearer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Invalid, expired, or already-used code")
     * )
     */
    public function exchange(Request $request): JsonResponse
    {
        $this->ensureSsoAvailable();

        $input = $request->validate([
            'code' => 'required|string|size:64',
        ]);

        $accessToken = $this->codes->redeem($input['code']);

        if (! $accessToken) {
            return response()->json(['error' => 'invalid or expired code'], 401);
        }

        return $this->OKResponse([
            'message' => 'authenticated',
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
        ]);
    }

    private function ensureSsoAvailable(): void
    {
        abort_unless(ApplicationMode::isStandalone() && config('sso.enabled'), 404);
    }

    private function errorRedirect(string $errorCode): RedirectResponse
    {
        $errorUrl = config('sso.frontend_error_url') ?: config('sso.frontend_callback_url');

        return redirect()->away(
            $errorUrl.(str_contains($errorUrl, '?') ? '&' : '?').http_build_query(['error' => $errorCode])
        );
    }
}
