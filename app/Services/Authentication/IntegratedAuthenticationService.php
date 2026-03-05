<?php

namespace App\Services\Authentication;

use App\Contracts\AuthenticationServiceInterface;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class IntegratedAuthenticationService implements AuthenticationServiceInterface
{
    public function authenticate(Request $request): mixed
    {
        $tokenString = $request->bearerToken() ?? session('token');

        if (! $tokenString) {
            return null;
        }

        try {
            $signer = new Sha256();
            $key = InMemory::plainText(config('integrated.jwt_secret'));
            $config = Configuration::forSymmetricSigner($signer, $key);

            $token = $config->parser()->parse($tokenString);
            /** @phpstan-ignore-next-line */
            $userClaims = $token->claims()->get('user');
        } catch (\Throwable $e) {
            return null; // Invalid token
        }

        // validate with external API
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'bearer '.$tokenString,
        ])->get(config('integrated.api_uri').'users/'.$userClaims['id']);

        if ($response->failed() || $response->json('message') !== 'success') {
            return null;
        }

        $userData = $response->json('data');

        $user = User::where('email', $userData['email'])->first();
        if (!$user) {
            $user = User::create([
                'email' => $userData['email'],
                'name' => $userData['name'],
                // LS: Removed as integrated mode, and Hash::make added a fair latency that is avoidable in
                // this instance.
                'password' => '',
            ]);
        } else {
            $user->fill([
                'name' => $userData['name'],
            ]);

            if ($user->isDirty()) {
                $user->save();
            }
        }

        return $user;
    }

    public function getRedirectUrlFromToken(string $tokenString): ?string
    {
        try {
            $signer = new Sha256();
            $key = InMemory::plainText(config('integrated.jwt_secret'));
            $config = Configuration::forSymmetricSigner($signer, $key);

            $token = $config->parser()->parse($tokenString);

            /** @phpstan-ignore-next-line */
            return $token->claims()->get('cohort_discovery_url');
        } catch (\Throwable $e) {
            return null; // Invalid token
        }
    }
}
