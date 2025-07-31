<?php

namespace App\Http\Controllers\Api\V1;

use Hash;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Bridge\User as PassportUser;
use Laravel\Passport\TokenRepository;
use Laravel\Passport\RefreshTokenRepository;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Signer\Key\InMemory;

use App\Traits\Responses;

class AuthController extends Controller
{
    use Responses;

    public function callbackForUser(Request $request): JsonResponse
    {
        $tokenString = session('token');

        $signer = new Sha256();
        $key = InMemory::plainText(env('GW_JWT_SECRET'));

        // Configure the parser. No validation needed, just parsing.
        $config = Configuration::forSymmetricSigner($signer, $key);
        $token = $config->parser()->parse($tokenString);

        $user = $token->claims()->get('user');
        
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'bearer ' . $tokenString,
        ])->get(env('GW_API_URI') . 'users/' . $user['id']);

        if (!$response->json()['message'] === 'success') {
            return $this->UnauthorisedResponse();
        }

        $user = $response->json()['data'];

        // Create user locally if not already available
        $localUser = User::firstOrCreate([
            'email' => $user['email'],
        ],
        [
            'name' => $user['name'],
            'email' => $user['email'],
            'password' => Hash::make(env('OAUTH_PLACEHOLDER_PASSWORD')),
        ]);

        return $this->OKResponse([
            'federated_token' => $tokenString,
            'type' => 'bearer',
            'federated_user_id' => $user['id'],
            'local_user_id' => $localUser->id,
        ]);
    }

    public function callbackForAuthToken(Request $request): RedirectResponse
    {
        $code = $request->input('code');
        if (!$code) {
            return $this->UnauthorisedResponse();
        }

        $response = Http::asForm()->post(env('GW_AUTHORISATION_URI'), [
            'grant_type' => 'authorization_code',
            'client_id' => env('GW_CLIENT_ID'),
            'client_secret' => env('GW_CLIENT_SECRET'),
            'redirect_uri' => 'http://localhost:8200/auth/callback',
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
