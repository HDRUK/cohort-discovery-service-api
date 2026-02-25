<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AuthenticationServiceInterface;
use App\Http\Controllers\Controller;
use App\Traits\Responses;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    use Responses;

    protected AuthenticationServiceInterface $authService;

    public function __construct(AuthenticationServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    public function callback(Request $request): JsonResponse|View
    {
        $code = $request->input('code');

        if (! $code) {
            return $this->UnauthorisedResponse();
        }

        $request->session()->put('oauth_callback_code', $code);

        return view('auth.loading');
    }

    public function callbackFinalize(Request $request): JsonResponse
    {
        $code = $request->session()->pull('oauth_callback_code');

        if (! $code) {
            return response()->json([
                'message' => 'Missing or expired authorisation code.',
            ], 401);
        }

        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(3)
            ->post(config('integrated.auth_uri'), [
                'grant_type' => 'authorization_code',
                'client_id' => config('integrated.client_id'),
                'client_secret' => config('integrated.client_secret'),
                'redirect_uri' => config('integrated.internal_oauth_callback_uri'),
                'code' => $code,
            ]);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Token exchange failed.',
            ], 401);
        }

        $tokenString = $response->json('token');
        if (! $tokenString) {
            return response()->json([
                'message' => 'Token exchange returned no token.',
            ], 401);
        }

        // Reuse existing auth service by setting token
        $request->session()->put('token', $tokenString);

        $user = $this->authService->authenticate($request);

        if (! $user) {
            return response()->json([
                'message' => 'User authentication failed.',
            ], 401);
        }

        $redirectUrl = $this->authService->getRedirectUrlFromToken($tokenString);

        session()->flash('federated_token', $tokenString);
        session()->flash('type', 'bearer');
        session()->flash('federated_user_id', $user->federated_id ?? null);
        session()->flash('local_user_id', $user->id);

        return response()->json([
            'redirect_url' => $redirectUrl ?? '/',
        ]);
    }

    public function callbackForAuthToken(Request $request): JsonResponse|RedirectResponse
    {
        $code = $request->input('code');

        if (! $code) {
            return $this->UnauthorisedResponse();
        }

        $response = Http::asForm()->post(config('integrated.auth_uri'), [
            'grant_type' => 'authorization_code',
            'client_id' => config('integrated.client_id'),
            'client_secret' => config('integrated.client_secret'),
            'redirect_uri' => config('integrated.internal_oauth_callback_uri'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            return $this->UnauthorisedResponse();
        }

        $token = $response->json()['token'];

        return redirect()->to('auth/callback2')
            ->with(['token' => $token]);
    }

    public function postDeviceCodeAuth(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return $this->UnauthorisedResponse();
        }

        return $this->OKResponse([
            'message' => 'authenticated',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }
}
