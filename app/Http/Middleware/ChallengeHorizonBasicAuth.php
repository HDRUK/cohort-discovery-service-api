<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ChallengeHorizonBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Gate::allows('viewHorizon')) {
            return $next($request);
        }

        return response('Unauthorised', 401, ['WWW-Authenticate' => 'Basic realm="Horizon"']);
    }
}
