<?php

namespace App\Http\Middleware;

use App\Models\Collection;
use App\Models\CollectionHost;
use App\Support\BasicAuthCredentials;
use App\Support\CollectionPollTarget;
use App\Traits\Responses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CollectionHostBasicAuth
{
    use Responses;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('system.basic_auth_enabled') === false) {
            return $next($request);
        }
        $startMicrotime = microtime(true);
        try {
            $credentials = BasicAuthCredentials::fromRequest($request);

            if (! $credentials) {
                \Log::error('No authorisation header ');
                return $this->UnauthorisedResponse();
            }

            $clientId = $credentials->clientId;

            // Look up client
            $client = CollectionHost::where('client_id', $clientId)->first();

            if (! $client) {
                \Log::error('Client cannot be found ');
                return $this->UnauthorisedResponse();
            }

            if (! hash_equals($client->client_secret, $credentials->clientSecret)) {
                \Log::error('Client is not authorised ');
                return $this->UnauthorisedResponse();
            }

            if (! $this->servesRequestedCollection($request, $client)) {
                \Log::error('Client does not serve the requested collection ');
                return $this->ForbiddenResponse();
            }

            return $next($request);
        } finally {
            $endMicrotime = microtime(true);
            $durationMs = ($endMicrotime - $startMicrotime) * 1000;
            \Log::debug(
                'Middleware CollectionHostBasicAuth finished in '. round($durationMs, 2) . ' ms',
                [
                    'duration_ms' => round($durationMs, 2),
                    'route'       => optional($request->route())->getName(),
                    'path'        => $request->path(),
                    'client_id'   => $clientId ?? null,
                ]
            );

        }

    }

    private function servesRequestedCollection(Request $request, CollectionHost $client): bool
    {
        $collectionPid = CollectionPollTarget::fromRouteParameter((string) $request->route('collectionPid'))->collectionPid;

        if (! Collection::where('pid', $collectionPid)->exists()) {
            return true;
        }

        return $client->collections()->where('collections.pid', $collectionPid)->exists();
    }
}
