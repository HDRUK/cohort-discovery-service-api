<?php

namespace App\Http\Middleware;

use App\Models\CollectionHost;
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
        $startMicrotime = microtime(true);


        if (config('system.basic_auth_enabled') === false) {
            return $next($request);
        }

        $authorisationHeader = $request->header('Authorization');

        if (! $authorisationHeader || ! preg_match('/Basic\s+(.*)$/i', $authorisationHeader, $matches)) {
            \Log::error('No authorisation header ');
            return $this->UnauthorisedResponse();
        }

        $encodedCredentials = $matches[1];
        $decodedCredentials = base64_decode($encodedCredentials, true);

        if (! $decodedCredentials || strpos($decodedCredentials, ':') === false) {
            return $this->UnauthorisedResponse();
        }

        [$clientId, $clientSecret] = explode(':', $decodedCredentials, 2);

        // Look up client
        $client = CollectionHost::where('client_id', $clientId)->first();

        if (! $client) {
            \Log::error('Client cannot be found ');
            return $this->UnauthorisedResponse();
        }

        if (! hash_equals($client->client_secret, $clientSecret)) {
            \Log::error('Client is not authorised ');
            return $this->UnauthorisedResponse();
        }

        $request->merge(['authenticated_client' => $client]);

        $endMicrotime = microtime(true);
        $durationMs = ($endMicrotime - $startMicrotime) * 1000;
        \Log::info('Middleware CollectionHostBasicAuth took '. round($durationMs, 2) . 'ms to run');


        return $next($request);
    }
}
