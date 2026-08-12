<?php

namespace App\Services\Sso;

use App\Exceptions\Sso\IdTokenValidationException;
use App\Exceptions\Sso\ProviderNotConfiguredException;
use Firebase\JWT\JWK;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OidcDiscoveryService
{
    private const DISCOVERY_CACHE_PREFIX = 'sso:disc:';
    private const JWKS_CACHE_PREFIX = 'sso:jwks:';

    /**
     * Fetch (and cache) the provider's OIDC discovery document.
     */
    public function metadata(OidcProviderConfig $provider): array
    {
        $metadata = Cache::remember(
            self::DISCOVERY_CACHE_PREFIX.$provider->slug,
            config('sso.discovery_cache_ttl_seconds', 3600),
            fn () => Http::timeout(10)->connectTimeout(3)
                ->get($provider->discoveryUrl)
                ->throw()
                ->json()
        );

        if (! is_array($metadata) || ($metadata['issuer'] ?? null) !== $provider->issuer) {
            throw new ProviderNotConfiguredException(
                "Discovery document issuer mismatch for provider [{$provider->slug}]"
            );
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
            if (empty($metadata[$endpoint])) {
                throw new ProviderNotConfiguredException(
                    "Discovery document for [{$provider->slug}] is missing [{$endpoint}]"
                );
            }
        }

        return $metadata;
    }

    /**
     * Fetch (and cache) the provider's JWKS, parsed into usable keys
     * keyed by kid.
     *
     * @return array<string, \Firebase\JWT\Key>
     */
    public function signingKeys(OidcProviderConfig $provider): array
    {
        $jwks = Cache::remember(
            self::JWKS_CACHE_PREFIX.$provider->slug,
            config('sso.jwks_cache_ttl_seconds', 3600),
            fn () => Http::timeout(10)->connectTimeout(3)
                ->get($this->metadata($provider)['jwks_uri'])
                ->throw()
                ->json()
        );

        if (! is_array($jwks) || empty($jwks['keys'])) {
            throw new IdTokenValidationException(
                "JWKS for provider [{$provider->slug}] is empty or malformed"
            );
        }

        return JWK::parseKeySet($jwks, 'RS256');
    }

    /**
     * Forget cached metadata + keys (e.g. after a kid miss during rotation).
     */
    public function forget(OidcProviderConfig $provider): void
    {
        Cache::forget(self::DISCOVERY_CACHE_PREFIX.$provider->slug);
        Cache::forget(self::JWKS_CACHE_PREFIX.$provider->slug);
    }
}
