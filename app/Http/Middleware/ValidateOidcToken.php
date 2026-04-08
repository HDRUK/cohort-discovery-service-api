<?php

namespace App\Http\Middleware;

use App\Services\Authentication\OIDCTokenValidator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ValidateOidcToken
{
    public function __construct(
        private readonly OIDCTokenValidator $validator,
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        if (! (bool) config('services.oidc.enabled', false)) {
            return $next($request);
        }

        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['error' => 'No token'], 401);
        }

        try {
            $validated = $this->validator->validateWithClaims($token);

            Auth::setUser($validated['user']);
            $request->attributes->set('jwt_claims', $validated['claims']);

            return $next($request);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid token: '.$e->getMessage()], 401);
        }
    }
}
