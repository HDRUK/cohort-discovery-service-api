<?php

namespace App\Http\Middleware;

use Closure;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Signer\Key\InMemory;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClaimBasedAccessControl
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$claims): Response
    {
        // TODO - As this requires Gateway being up to date with workgroups,
        // which it currently is not, we will stub this out for now.

        return $next($request);
    }
}