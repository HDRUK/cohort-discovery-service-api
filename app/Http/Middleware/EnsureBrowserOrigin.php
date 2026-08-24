<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Rejects requests that do not look like they came from a trusted frontend
 * running in a browser.
 *
 * Two checks: the Origin header must be in the configured allowlist, and a
 * Sec-Fetch-Site header must be present. Browsers set both automatically and
 * page JavaScript cannot forge either (both are forbidden header names), so
 * this blocks casual scripted abuse and cross-site calls.
 *
 * This is defence-in-depth, NOT proof of a browser: a determined caller with a
 * valid JWT can still forge these headers with a tool like curl. The real
 * guards on the click endpoint are the JWT requirement, the rate limiter and
 * the dedup window - this simply raises the bar.
 */
class EnsureBrowserOrigin
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowed = config('clicks.allowed_origins', []);

        if (! $origin || ! in_array($origin, $allowed, true)) {
            return $this->forbidden();
        }

        if (! $request->headers->has('Sec-Fetch-Site')) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden()
    {
        return response()->json([
            'message' => 'forbidden',
            'data' => null,
        ], 403);
    }
}
