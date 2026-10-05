<?php

namespace App\Services\Claims;

use Carbon\CarbonInterface;

class ClaimSyncPolicy
{
    public const TRUST_NEVER = 'never';

    public const TRUST_FIRST_LOGIN = 'first_login';

    public const TRUST_ALWAYS = 'always';

    public const SUBJECT_WORKGROUPS = 'workgroups';

    public const SUBJECT_ROLES = 'roles';

    public const SUBJECT_CUSTODIANS = 'custodians';

    public function shouldSync(string $subject, ?CarbonInterface $claimsSyncedAt): bool
    {
        return match ($this->trustedModeOrNever($subject)) {
            self::TRUST_ALWAYS => true,
            self::TRUST_FIRST_LOGIN => $claimsSyncedAt === null,
            default => false,
        };
    }

    public function trustedModeOrNever(string $subject): string
    {
        $trust = config("claimsaccesscontrol.sync.{$subject}.trust");

        $known = [self::TRUST_NEVER, self::TRUST_FIRST_LOGIN, self::TRUST_ALWAYS];

        return in_array($trust, $known, true) ? $trust : self::TRUST_NEVER;
    }

    public function isAuthoritative(string $subject): bool
    {
        return (bool) config("claimsaccesscontrol.sync.{$subject}.authoritative", false);
    }

    public function shouldEnsureDefaultWorkgroup(): bool
    {
        return (bool) config('claimsaccesscontrol.sync.workgroups.ensure_default', true);
    }

    public function shouldSyncSdeWorkgroupsFromClaim(): bool
    {
        return (bool) config('claimsaccesscontrol.sync.workgroups.sde_from_claim', true);
    }

    public function shouldApplyDefaultsOnCreate(): bool
    {
        return (bool) config('claimsaccesscontrol.sync.provision.defaults_on_create', true);
    }
}
