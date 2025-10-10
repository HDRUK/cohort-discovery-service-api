<?php

namespace App\Services\Authentication;

use App\Contracts\AuthenticationServiceInterface;
use Hash;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\PersonalAccessTokenFactory;
use App\Models\User;

class StandaloneAuthenticationService implements AuthenticationServiceInterface
{
    public function authenticate(Request $request): mixed
    {
        $credentials = $request->only('email', 'password');

        $user = User::where('email', $credentials['email'])->first();
        if ($user && Hash::check($credentials['password'], $user->password)) {
            $client = Client::where('provider', 'users')
                ->where('revoked', 0)
                ->get()
                /** @phpstan-ignore-next-line */
                ->first(fn ($c) => in_array('personal_access', $c->grant_types, true));

            $tokenFactory = app(PersonalAccessTokenFactory::class);
            $token = $tokenFactory->make(
                $user->getKey(),
                'local_login',
                ['*'],
                $client->provider
            );

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
