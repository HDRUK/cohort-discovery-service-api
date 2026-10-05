<?php

namespace App\Services\Sso;

final readonly class OidcProviderMetadata
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $userinfoEndpoint,
        public ?string $endSessionEndpoint,
    ) {
    }

    public static function fromDiscoveryDocument(array $document): self
    {
        return new self(
            issuer: (string) $document['issuer'],
            authorizationEndpoint: (string) $document['authorization_endpoint'],
            tokenEndpoint: (string) $document['token_endpoint'],
            jwksUri: (string) $document['jwks_uri'],
            userinfoEndpoint: ($document['userinfo_endpoint'] ?? null) ?: null,
            endSessionEndpoint: ($document['end_session_endpoint'] ?? null) ?: null,
        );
    }
}
