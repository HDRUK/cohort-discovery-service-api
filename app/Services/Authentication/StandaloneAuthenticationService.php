<?php

namespace App\Services\Authentication;

use App\Contracts\AuthenticationServiceInterface;
use App\Models\User;
use Hash;
use Illuminate\Http\Request;

class StandaloneAuthenticationService implements AuthenticationServiceInterface
{
    private LocalPersonalAccessTokenService $tokenService;

    public function __construct(LocalPersonalAccessTokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    public function authenticate(Request $request): mixed
    {
        $credentials = $request->only('email', 'password');

        $user = User::where('email', $credentials['email'])->first();

        if ($user && Hash::check($credentials['password'], $user->password)) {
            $token = $this->tokenService->makeForUser($user);

            return [
                'access_token' => $token->accessToken,
                'token_type' => 'Bearer',
            ];
        }

        return null;
    }

    public function getRedirectUrlFromToken(string $tokenString): ?string
    {
        // Not applicable in standalone mode
        return null;
    }
}
