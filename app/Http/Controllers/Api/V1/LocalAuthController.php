<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AuthenticationServiceInterface;
use App\Http\Controllers\Controller;
use App\Traits\Responses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocalAuthController extends Controller
{
    use Responses;

    protected AuthenticationServiceInterface $authService;

    public function __construct(AuthenticationServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = $this->authService->authenticate($request);

        if (! $user) {
            return $this->UnauthorisedResponse();
        }

        return $this->OKResponse([
            'message' => 'authenticated',
            'access_token' => $user['access_token'],
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            $token = $user->currentAccessToken();
            /** @phpstan-ignore-next-line */
            $token?->delete();
        }

        return $this->OKResponse(['message' => 'logged out']);
    }
}
