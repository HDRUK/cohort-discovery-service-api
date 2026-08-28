<?php

namespace App\Services\Sso;

use App\Exceptions\Sso\ProviderNotConfiguredException;

final readonly class OidcProviderConfig
{
    public function __construct(
        public string $slug,
        public string $label,
        public string $issuer,
        public string $discoveryUrl,
        public string $clientId,
        public string $clientSecret,
        public array $scopes,
        public string $redirectUri,
    ) {
    }

    /**
     * @throws ProviderNotConfiguredException for unknown or disabled slugs
     */
    public static function fromConfig(string $slug): self
    {
        $config = config("sso.providers.{$slug}");

        if (! is_array($config) || ! ($config['enabled'] ?? false)) {
            throw new ProviderNotConfiguredException("SSO provider [{$slug}] is not configured or not enabled");
        }

        foreach (['issuer', 'client_id', 'client_secret'] as $required) {
            if (empty($config[$required])) {
                throw new ProviderNotConfiguredException("SSO provider [{$slug}] is missing required config [{$required}]");
            }
        }

        $issuer = rtrim($config['issuer'], '/');

        return new self(
            slug: $slug,
            label: $config['label'] ?? $slug,
            issuer: $issuer,
            discoveryUrl: $config['discovery_url'] ?: $issuer.'/.well-known/openid-configuration',
            clientId: $config['client_id'],
            clientSecret: $config['client_secret'],
            scopes: preg_split('/[\s,]+/', $config['scopes'] ?? 'openid profile email', -1, PREG_SPLIT_NO_EMPTY),
            redirectUri: $config['redirect_uri'] ?: url("/api/auth/sso/{$slug}/callback"),
        );
    }

    /**
     * Enabled providers for the frontend: slug => label.
     */
    public static function enabledProviders(): array
    {
        return collect(config('sso.providers', []))
            ->filter(fn ($config) => is_array($config) && ($config['enabled'] ?? false))
            ->map(fn ($config, $slug) => $config['label'] ?? $slug)
            ->all();
    }
}
