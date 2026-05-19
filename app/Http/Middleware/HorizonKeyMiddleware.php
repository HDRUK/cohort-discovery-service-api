<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HorizonKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $key = (string) $request->query('key', '');
        $secret = (string) config('integrated.jwt_secret', '');

        // Optional: if secret not set, fail closed.
        if ($secret === '') {
            abort(403, 'Horizon key not configured.');
        }

        // Use hash_equals to avoid timing attacks.
        if ($key === '' || !hash_equals($secret, $key)) {
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
