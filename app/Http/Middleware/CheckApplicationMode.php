<?php

namespace App\Http\Middleware;

use Closure;
use App\Support\ApplicationMode;

class CheckApplicationMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next): mixed
    {
        $request->attributes->set('x-application-mode', ApplicationMode::isStandalone() ? 'standalone' : 'integrated');
        return $next($request);
    }
}
