<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AuthenticationServiceInterface;
use App\Http\Controllers\Controller;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class AuthController extends Controller
{
    use Responses;

    protected AuthenticationServiceInterface $authService;

    public function __construct(AuthenticationServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    public function callbackForUser(Request $request): JsonResponse|RedirectResponse
    {
        $user = $this->authService->authenticate($request);

        if (! $user) {
            return $this->UnauthorisedResponse();
        }

        $tokenString = session('token');
        $redirectUrl = $this->authService->getRedirectUrlFromToken($tokenString);

        return redirect()->to($redirectUrl ?? '/')
            ->with([
                'federated_token' => $tokenString,
                'type' => 'bearer',
                'federated_user_id' => $user->federated_id ?? null,
                'local_user_id' => $user->id,
            ]);
    }

    public function callbackForAuthToken(Request $request): JsonResponse|RedirectResponse
    {
        $code = $request->input('code');

        if (! $code) {
            return $this->UnauthorisedResponse();
        }

        return response()->json([
            'full_config' => config('integrated'),
            'url' => config('integrated.auth_uri'),
            'url2' => Config::get('integrated.auth_uri', 'no-value-found')
        ]);

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
