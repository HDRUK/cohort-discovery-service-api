<?php

namespace App\Services\Sso;

/**
 * Per-provider rules for turning IdP claims into local roles and workgroups.
 *
 * Disabled by default. When it is off, the local database is the sole
 * authority on what a user may do and the IdP only ever proves who they are.
 */
final readonly class ClaimMappingConfig
{
    public const AUTHORITY_LOCAL = 'local';

    public const AUTHORITY_IDP = 'idp';

    public function __construct(
        public bool $enabled,
        public string $authority,
        public string $workgroupsClaim,
        public ?string $rolesClaim,
        public array $roleMap,
    ) {
    }

    public static function fromArray(array $config): self
    {
        $authority = $config['authority'] ?? self::AUTHORITY_LOCAL;

        // Anything we don't recognise falls back to the non-destructive
        // option - a typo in .env should never start stripping people's
        // workgroups on login
        if (! in_array($authority, [self::AUTHORITY_LOCAL, self::AUTHORITY_IDP], true)) {
            $authority = self::AUTHORITY_LOCAL;
        }

        $rolesClaim = $config['roles_claim'] ?? null;

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            authority: $authority,
            workgroupsClaim: ($config['workgroups_claim'] ?? null) ?: 'eduperson_entitlement',
            rolesClaim: $rolesClaim ?: null,
            roleMap: is_array($config['role_map'] ?? null) ? $config['role_map'] : [],
        );
    }

    /**
     * Whether membership the IdP does not mention should be removed.
     */
    public function isIdpAuthoritative(): bool
    {
        return $this->authority === self::AUTHORITY_IDP;
    }
}
