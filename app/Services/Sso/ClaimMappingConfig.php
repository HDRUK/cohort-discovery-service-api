<?php

namespace App\Services\Sso;

final readonly class ClaimMappingConfig
{
    public function __construct(
        public string $workgroupsClaim,
        public ?string $rolesClaim,
    ) {
    }

    public static function fromArray(array $config): self
    {
        $rolesClaim = $config['roles_claim'] ?? null;

        return new self(
            workgroupsClaim: ($config['workgroups_claim'] ?? null) ?: 'eduperson_entitlement',
            rolesClaim: $rolesClaim ?: null,
        );
    }
}
