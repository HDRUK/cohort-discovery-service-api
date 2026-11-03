<?php

namespace App\Http\Middleware;

use App\Models\ServiceAccount;
use App\Support\BasicAuthCredentials;
use App\Traits\Responses;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class ServiceAccountBasicAuth
{
    use Responses;

    public function handle(Request $request, Closure $next): Response
    {
        if (config('system.basic_auth_enabled') === false) {
            return $next($request);
        }

        $credentials = BasicAuthCredentials::fromRequest($request);

        if (! $credentials) {
            return $this->UnauthorisedResponse();
        }

        $serviceAccount = ServiceAccount::where('client_id', $credentials->clientId)->first();

        if (! $serviceAccount || ! Hash::check($credentials->clientSecret, $serviceAccount->client_secret)) {
            \Log::error('Service account is not authorised', ['client_id' => $credentials->clientId]);

            return $this->UnauthorisedResponse();
        }

        return $next($request);
    }
}
