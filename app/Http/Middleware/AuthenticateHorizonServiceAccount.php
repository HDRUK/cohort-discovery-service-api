<?php

namespace App\Http\Middleware;

use App\Models\ServiceAccount;
use App\Support\BasicAuthCredentials;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHorizonServiceAccount
{
    public const AUTHENTICATED = 'horizon.authenticated';

    public const PROTECTION_FEATURE = 'horizon-protection';

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local') || ! Feature::active(self::PROTECTION_FEATURE)) {
            return $next($request);
        }

        if (! $this->hasHorizonCredentials(BasicAuthCredentials::fromRequest($request))) {
            return response()->json(
                ['message' => 'Horizon requires the horizon service account credentials.'],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Basic realm="Horizon"']
            );
        }

        $request->attributes->set(self::AUTHENTICATED, true);

        return $next($request);
    }

    private function hasHorizonCredentials(?BasicAuthCredentials $credentials): bool
    {
        if (! $credentials) {
            return false;
        }

        $serviceAccount = ServiceAccount::where('name', ServiceAccount::HORIZON)
            ->where('client_id', $credentials->clientId)
            ->first();

        if (! $serviceAccount || ! Hash::check($credentials->clientSecret, $serviceAccount->client_secret)) {
            Log::error('Horizon access was not authorised', ['client_id' => $credentials->clientId]);

            return false;
        }

        return true;
    }
}
