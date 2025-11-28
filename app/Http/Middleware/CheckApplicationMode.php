<?php

namespace App\Http\Middleware;

use App\Support\ApplicationMode;
use Closure;

class CheckApplicationMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function handle($request, Closure $next): mixed
    {
        $request->attributes->set('x-application-mode', ApplicationMode::isStandalone() ? 'standalone' : 'integrated');

        return $next($request);
    }
}
