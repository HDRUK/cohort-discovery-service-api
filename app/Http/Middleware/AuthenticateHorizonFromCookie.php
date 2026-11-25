<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHorizonFromCookie
{
    public function __construct(private readonly DecodeJwt $decodeJwt)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookies->get((string) config('horizon.token_cookie'));

        if (is_string($token) && $token !== '') {
            $request->headers->set('Authorization', 'Bearer '.$token);
            $this->decodeJwt->handle($request, fn () => response(''));
            $request->headers->remove('Authorization');
        }

        return $next($request);
    }
}
