<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token;
use App\Support\ApplicationMode;
use App\Models\Custodian;
use App\Models\User;

class DecodeJwt
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!ApplicationMode::isStandalone()) {
            if (! $token) {
                return response()->json(['error' => 'No token'], 401);
            }

            try {
                $key = config('integrated.jwt_secret');

                if (!$key) {
                    throw new \Exception('No jwt secret provided, cant decode safely');
                }
                $claims = JWT::decode($token, new Key($key, 'HS256'));

                $request->attributes->set('jwt_claims', (array) $claims);

                $jwtUser = $claims->user ?? null;
                if (!$jwtUser) {
                    return response()->json(['error' => 'Invalid token: Unknown user'], 401);
                }
                $userEmail = $jwtUser->email;
                $teams = $jwtUser->admin_teams;

                foreach ($teams as $team) {
                    $custodian = Custodian::updateOrCreate(
                        ['gateway_team_id' => $team->id],
                        [
                            'name' => $team->name,
                            'gateway_team_name' => $team->name,
                        ]
                    );

                    if ($custodian->wasRecentlyCreated) {
                        $custodian->pid = Str::uuid();
                        $custodian->save();
                    }
                }

                if ($userEmail) {
                    $user = User::where('email', strtolower($userEmail))->first();

                    if ($user) {
                        Auth::setUser($user);
                    } else {
                        return response()->json(['error' => 'Cannot find token user in local database'], 401);
                    }
                }
            } catch (\Exception $e) {
                return response()->json(['error' => 'Invalid token: ' . $e->getMessage()], 401);
            }
        } else {
            // Standalone mode - token signed internally, rather than externally
            $jwtConfig = Configuration::forAsymmetricSigner(
                new \Lcobucci\JWT\Signer\Rsa\Sha256(),
                InMemory::file(storage_path('oauth-private.key')),
                InMemory::file(storage_path('oauth-public.key'))
            );

            /** @var \Lcobucci\JWT\UnencryptedToken $jwt */
            $jwt = $jwtConfig->parser()->parse($token);

            $jwtUser = $jwt->claims()->get('user');
            if (!$jwtUser) {
                return response()->json(['error' => 'Invalid token: Unknown user'], 401);
            }

            $user = User::where('email', $jwtUser['email'])->first();
            if ($user) {
                Auth::setUser($user);
                $request->attributes->set('jwt_claims', $jwt->claims()->all());
            } else {
                return response()->json(['error' => 'cannot find token user in local database'], 401);
            }
        }


        return $next($request);
    }
}
