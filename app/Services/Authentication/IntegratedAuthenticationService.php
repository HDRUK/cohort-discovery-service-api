<?php

namespace App\Services\Authentication;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use App\Contracts\AuthenticationServiceInterface;
use App\Models\User;

class IntegratedAuthenticationService implements AuthenticationServiceInterface
{
    public function authenticate(Request $request): mixed
    {

        $tokenString = $request->bearerToken() ?? session('token');

        if (!$tokenString) {
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
            'Authorization' => 'bearer ' . $tokenString,
        ])->get(config('integrated.api_uri') . 'users/' . $userClaims['id']);

        if ($response->failed() || $response->json('message') !== 'success') {
            return null;
        }

        $userData = $response->json('data');

        // Create / update local shadow user
        $user = User::updateOrCreate(
            [
                'email' => $userData['email'],
            ],
            [
                'name' => $userData['name'],
                'password' => Hash::make(config('integrated.placeholder_password')),
            ]
        );

        // Optionally, store federated IDs if needed
        // $user->federated_id = $userData['id'];
        // $user->save();

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
