<?php

namespace App\Services\Sso;

final readonly class OidcAuthResult
{
    public function __construct(
        public string $sub,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
        public array $rawClaims,
    ) {
    }

    public static function fromClaims(array $claims): self
    {
        return new self(
            sub: (string) $claims['sub'],
            email: isset($claims['email']) ? strtolower((string) $claims['email']) : null,
            emailVerified: ($claims['email_verified'] ?? false) === true,
            name: isset($claims['name']) ? (string) $claims['name'] : null,
            rawClaims: $claims,
        );
    }
}
