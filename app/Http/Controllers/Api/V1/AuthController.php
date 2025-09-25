<?php

namespace App\Http\Controllers\Api\V1;

use Hash;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Signer\Key\InMemory;
use App\Traits\Responses;

class AuthController extends Controller
{
    use Responses;

    public function callbackForUser(Request $request): JsonResponse|RedirectResponse
    {
        $tokenString = session('token');

        $signer = new Sha256();
        $key = InMemory::plainText(config('gateway.jwt_secret'));

        // Configure the parser. No validation needed, just parsing.
        $config = Configuration::forSymmetricSigner($signer, $key);
        $token = $config->parser()->parse($tokenString);

        /** @phpstan-ignore-next-line */
        $user = $token->claims()->get('user');

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'bearer ' . $tokenString,
        ])->get(config('gateway.api_uri') . 'users/' . $user['id']);

        if ($response->json()['message'] !== 'success') {
            return $this->UnauthorisedResponse();
        }

        $user = $response->json()['data'];

        // Create user locally if not already available
        $localUser = User::firstOrCreate(
            [
                'email' => $user['email'],
            ],
            [
                'name' => $user['name'],
                'email' => $user['email'],
                'password' => Hash::make(config('gateway.placeholder_password')),
            ]
        );

        /** @phpstan-ignore-next-line */
        $redirectUrl = $token->claims()->get('cohort_discovery_url');

        return redirect()->to($redirectUrl)
            ->with([
                'federated_token' => $tokenString,
                'type' => 'bearer',
                'federated_user_id' => $user['id'],
                'local_user_id' => $localUser->id,
            ]);
    }

    public function callbackForAuthToken(Request $request): JsonResponse|RedirectResponse
    {
        $code = $request->input('code');
        if (!$code) {
            return $this->UnauthorisedResponse();
        }

        $response = Http::asForm()->post(config('gateway.auth_uri'), [
            'grant_type' => 'authorization_code',
            'client_id' => config('gateway.client_id'),
            'client_secret' => config('gateway.client_secret'),
            'redirect_uri' => config('gateway.internal_oauth_callback_uri'),
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

        if (!$user) {
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
