<?php

namespace App\Services\Sso;

final readonly class ClaimMappingConfig
{
    public function __construct(
        public string $workgroupsClaim,
        public ?string $rolesClaim,
        public ?string $custodiansClaim,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            workgroupsClaim: ($config['workgroups_claim'] ?? null) ?: 'eduperson_entitlement',
            rolesClaim: ($config['roles_claim'] ?? null) ?: null,
            custodiansClaim: ($config['custodians_claim'] ?? null) ?: null,
        );
    }
}
