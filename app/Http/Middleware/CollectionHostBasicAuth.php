<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use App\Models\CollectionHost;

use App\Traits\Responses;

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

        $authorisationHeader = $request->header('Authorization');

        if (!$authorisationHeader || !preg_match('/Basic\s+(.*)$/i', $authorisationHeader, $matches)) {
            return $this->UnauthorisedResponse();
        }

        $encodedCredentials = $matches[1];
        $decodedCredentials = base64_decode($encodedCredentials, true);

        if (!$decodedCredentials || strpos($decodedCredentials, ':') === false) {
            return $this->UnauthorisedResponse();
        }

        [$clientId, $clientSecret] = explode(':', $decodedCredentials, 2);

        // Look up client
        $client = CollectionHost::where('client_id', $clientId)->first();

        if (!$client) {
            return $this->UnauthorisedResponse();
        }

        if (!hash_equals($client->client_secret, $clientSecret)) {
            return $this->UnauthorisedResponse();
        }

        $request->merge(['authenticated_client' => $client]);

        return $next($request);
    }
}
