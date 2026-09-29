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

        // The discovery document is fetched over the network, so treat every
        // URL in it as attacker-controlled until proven otherwise: a hostile
        // or compromised one could otherwise point jwks_uri at an internal
        // address and have us fetch it, or at a host whose keys it controls
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint', 'end_session_endpoint'] as $endpoint) {
            if (! empty($metadata[$endpoint])) {
                $this->assertUrlBelongsToIssuer($provider, (string) $metadata[$endpoint], $endpoint);
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

    /**
     * Require a discovered URL to live on the same origin as the issuer.
     *
     * Scheme, host and port must all match. Ported from the OIDC resource
     * server that this replaced, where it landed as a fix for exactly this
     * class of SSRF.
     */
    private function assertUrlBelongsToIssuer(OidcProviderConfig $provider, string $url, string $context): void
    {
        $urlParts = parse_url($url);
        $issuerParts = parse_url($provider->issuer);

        if (! is_array($urlParts) || ! is_array($issuerParts)) {
            throw new ProviderNotConfiguredException(
                "Discovery document for [{$provider->slug}] has an invalid [{$context}] URL"
            );
        }

        $urlScheme = strtolower($urlParts['scheme'] ?? '');
        $issuerScheme = strtolower($issuerParts['scheme'] ?? '');
        $urlHost = strtolower($urlParts['host'] ?? '');
        $issuerHost = strtolower($issuerParts['host'] ?? '');

        if ($urlScheme === '' || $urlHost === '' || $issuerScheme === '' || $issuerHost === '') {
            throw new ProviderNotConfiguredException(
                "Discovery document for [{$provider->slug}] has an invalid [{$context}] URL"
            );
        }

        $urlPort = $urlParts['port'] ?? $this->defaultPortForScheme($urlScheme);
        $issuerPort = $issuerParts['port'] ?? $this->defaultPortForScheme($issuerScheme);

        if ($urlScheme !== $issuerScheme || $urlHost !== $issuerHost || $urlPort !== $issuerPort) {
            throw new ProviderNotConfiguredException(
                "Discovery document [{$context}] for [{$provider->slug}] does not belong to the configured issuer"
            );
        }
    }

    private function defaultPortForScheme(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
