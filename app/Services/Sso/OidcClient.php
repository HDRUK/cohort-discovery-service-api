<?php

namespace App\Services\Sso;

use App\Exceptions\Sso\IdTokenValidationException;
use App\Exceptions\Sso\InvalidStateException;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OIDC relying-party protocol engine: authorisation code + PKCE (S256).
 *
 * The state/nonce/verifier trio lives in cache keyed by state, deliberately
 * NOT in the session - the FE is cross-origin (SameSite cookies would ruin
 * our day) and we run under Octane. Cache::pull gives us single-use
 * transactions for free.
 */
class OidcClient
{
    private const TXN_CACHE_PREFIX = 'sso:txn:';

    public function __construct(
        private readonly OidcDiscoveryService $discovery,
    ) {
    }

    /**
     * Create an authorisation transaction and return the IdP authorise URL.
     */
    public function buildAuthorizationRedirect(OidcProviderConfig $provider): string
    {
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');

        Cache::put(
            self::TXN_CACHE_PREFIX.$state,
            [
                'provider' => $provider->slug,
                'nonce' => $nonce,
                'code_verifier' => $codeVerifier,
            ],
            config('sso.transaction_ttl_seconds', 600)
        );

        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->clientId,
            'redirect_uri' => $provider->redirectUri,
            'scope' => implode(' ', $provider->scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return $this->discovery->metadata($provider)['authorization_endpoint'].'?'.$query;
    }

    /**
     * Complete the flow: consume the transaction (single use), exchange the
     * code, validate the id_token, and return the user's claims.
     */
    public function handleCallback(OidcProviderConfig $provider, string $code, string $state): OidcAuthResult
    {
        $txn = Cache::pull(self::TXN_CACHE_PREFIX.$state);

        if (! is_array($txn)) {
            throw new InvalidStateException('Unknown, expired, or already-used state parameter');
        }

        if ($txn['provider'] !== $provider->slug) {
            throw new InvalidStateException('State parameter was issued for a different provider');
        }

        $metadata = $this->discovery->metadata($provider);

        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(3)
            ->post($metadata['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $provider->redirectUri,
                'client_id' => $provider->clientId,
                'client_secret' => $provider->clientSecret,
                'code_verifier' => $txn['code_verifier'],
            ]);

        if ($response->failed()) {
            throw new IdTokenValidationException(
                'Token endpoint returned '.$response->status().' for provider ['.$provider->slug.']'
            );
        }

        $idToken = $response->json('id_token');
        if (! is_string($idToken) || $idToken === '') {
            throw new IdTokenValidationException('Token endpoint response contained no id_token');
        }

        $claims = $this->validateIdToken($idToken, $provider, $txn['nonce']);

        if ((empty($claims['email']) || empty($claims['name'])) && ! empty($metadata['userinfo_endpoint'])) {
            $claims = $this->mergeUserinfoClaims(
                $claims,
                $metadata['userinfo_endpoint'],
                (string) $response->json('access_token')
            );
        }

        return OidcAuthResult::fromClaims($claims);
    }

    /**
     * Validate signature (JWKS), iss, aud, azp, exp/iat (with leeway) and
     * nonce. Returns the id_token claims.
     */
    public function validateIdToken(string $idToken, OidcProviderConfig $provider, string $expectedNonce): array
    {
        JWT::$leeway = (int) config('sso.id_token_leeway_seconds', 30);

        try {
            $decoded = JWT::decode($idToken, $this->discovery->signingKeys($provider));
        } catch (\UnexpectedValueException $e) {
            // IdPs rotate their signing keys whenever the mood takes them -
            // one retry with a fresh JWKS covers it, anything more is a
            // genuinely bad token
            $this->discovery->forget($provider);

            try {
                $decoded = JWT::decode($idToken, $this->discovery->signingKeys($provider));
            } catch (\UnexpectedValueException $retryException) {
                throw new IdTokenValidationException('id_token validation failed: '.$retryException->getMessage());
            }
        } finally {
            JWT::$leeway = 0;
        }

        $claims = json_decode(json_encode($decoded), true);

        if (($claims['iss'] ?? null) !== $provider->issuer) {
            throw new IdTokenValidationException('id_token issuer mismatch');
        }

        $audience = (array) ($claims['aud'] ?? []);
        if (! in_array($provider->clientId, $audience, true)) {
            throw new IdTokenValidationException('id_token audience mismatch');
        }

        if (isset($claims['azp']) && $claims['azp'] !== $provider->clientId) {
            throw new IdTokenValidationException('id_token authorized party mismatch');
        }

        if (! isset($claims['nonce']) || ! hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new IdTokenValidationException('id_token nonce mismatch');
        }

        if (empty($claims['sub'])) {
            throw new IdTokenValidationException('id_token has no sub claim');
        }

        return $claims;
    }

    private function mergeUserinfoClaims(array $claims, string $userinfoEndpoint, string $accessToken): array
    {
        if ($accessToken === '') {
            return $claims;
        }

        // Userinfo is best effort garnish - if the id_token already passed
        // the full validation gauntlet, a flaky userinfo endpoint is no
        // reason to fail the login
        try {
            $userinfo = Http::withToken($accessToken)
                ->timeout(10)
                ->connectTimeout(3)
                ->get($userinfoEndpoint);
        } catch (\Throwable) {
            return $claims;
        }

        if ($userinfo->failed() || ! is_array($userinfo->json())) {
            return $claims;
        }

        $info = $userinfo->json();

        // Userinfo must describe the same subject as the id_token
        if (($info['sub'] ?? null) !== $claims['sub']) {
            throw new IdTokenValidationException('userinfo subject does not match id_token subject');
        }

        return array_merge($info, $claims);
    }
}
