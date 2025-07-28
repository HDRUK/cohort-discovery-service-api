<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogHttpRequests
{
    public function handle(Request $request, Closure $next)
    {
        Log::info('Incoming Request', [
            'method' => $request->method(),
            'uri' => $request->getRequestUri(),
            'ip' => $request->ip(),
            'body' => $request->all(),
        ]);

        return $next($request);
    }
}
