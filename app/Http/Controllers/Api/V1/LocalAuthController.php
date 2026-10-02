<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AuthenticationServiceInterface;
use App\Http\Controllers\Controller;
use App\Services\Authentication\LoginMethods;
use App\Support\ApplicationMode;
use App\Traits\Responses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocalAuthController extends Controller
{
    use Responses;

    protected AuthenticationServiceInterface $authService;

    protected LoginMethods $loginMethods;

    public function __construct(AuthenticationServiceInterface $authService, LoginMethods $loginMethods)
    {
        $this->authService = $authService;
        $this->loginMethods = $loginMethods;
    }

    public function login(Request $request)
    {
        // Integrated mode reaches here too, but validates a Gateway bearer
        // token rather than a password, so the switch must not touch it.
        if (ApplicationMode::isStandalone() && ! $this->loginMethods->passwordEnabled()) {
            return response()->json([
                'message' => 'password login is disabled on this deployment',
                'data' => null,
            ], 403);
        }

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
