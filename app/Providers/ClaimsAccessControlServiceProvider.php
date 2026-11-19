<?php

namespace App\Providers;

use App\Enums\ClaimTrust;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\Claims\MembershipSources;
use Illuminate\Support\ServiceProvider;

class ClaimsAccessControlServiceProvider extends ServiceProvider
{
    public const FLAGS = [
        'workgroups.authoritative' => 'CLAIM_SYNC_WORKGROUPS_AUTHORITATIVE',
        'workgroups.ensure_default' => 'CLAIM_SYNC_ENSURE_DEFAULT_WORKGROUP',
        'workgroups.sde_from_claim' => 'CLAIM_SYNC_SDE_WORKGROUPS_FROM_CLAIM',
        'roles.authoritative' => 'CLAIM_SYNC_ROLES_AUTHORITATIVE',
        'roles.ensure_default' => 'CLAIM_SYNC_ENSURE_DEFAULT_ROLE',
        'custodians.authoritative' => 'CLAIM_SYNC_CUSTODIANS_AUTHORITATIVE',
        'provision.defaults_on_create' => 'CLAIM_PROVISION_DEFAULTS_ON_CREATE',
    ];

    public const ACCEPTED_FLAG_VALUES = [true, 'true', 1, '1', false, 'false', 0, '0', ''];

    public function register(): void
    {
        $this->assertTrustIsValid();
        $this->assertFlagsAreValid();

        $this->app->scoped(MembershipSources::class);
    }

    private function assertTrustIsValid(): void
    {
        foreach (ClaimSyncPolicy::SUBJECTS as $subject) {
            $trust = config("claimsaccesscontrol.sync.{$subject}.trust");

            if (is_string($trust) && ClaimTrust::tryFrom($trust) !== null) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'CLAIM_SYNC_%s is [%s]; expected one of: %s (claimsaccesscontrol.sync.%s.trust).',
                strtoupper($subject),
                $this->describe($trust),
                implode(', ', array_column(ClaimTrust::cases(), 'value')),
                $subject
            ));
        }
    }

    private function assertFlagsAreValid(): void
    {
        foreach (self::FLAGS as $key => $envName) {
            $value = config("claimsaccesscontrol.sync.{$key}");

            if (in_array($value, self::ACCEPTED_FLAG_VALUES, true)) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                '%s is [%s]; expected one of: true, false, 1, 0, or blank for false (claimsaccesscontrol.sync.%s).',
                $envName,
                $this->describe($value),
                $key
            ));
        }
    }

    private function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : gettype($value);
    }
}
