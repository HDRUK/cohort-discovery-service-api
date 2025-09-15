<?php

namespace App\Http\Middleware;

use App\Models\Custodian;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DecodeJwt
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['error' => 'No token'], 401);
        }

        try {
            $key = config('api.gateway_jwt_secret');

            if (!$key) {
                throw new \Exception('No gateway jwt secret provided, cant decode safely');
            }
            $claims = JWT::decode($token, new Key($key, 'HS256'));

            $request->attributes->set('jwt_claims', (array) $claims);

            $jwtUser = $claims->user ?? null;
            $userEmail = $jwtUser->email;
            $teams = $jwtUser->teams;
            foreach ($teams as $team) {
                Custodian::firstOrCreate(
                    ['gateway_team_id' => $team->id],
                    [
                        'pid' => Str::uuid(),
                        'name' => $team->name,
                        'gateway_team_name' => $team->name,
                    ]
                );
            }

            if ($userEmail) {
                $user = User::where('email', $userEmail)->first();

                if ($user) {
                    Auth::setUser($user);
                } else {
                    return response()->json(['error' => 'Cannot find token user in local database'], 401);
                }
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid token: ' . $e->getMessage()], 401);
        }

        return $next($request);
    }
}
