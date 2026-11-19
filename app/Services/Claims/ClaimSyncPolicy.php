<?php

namespace App\Services\Claims;

use App\Enums\ClaimTrust;
use App\Enums\MembershipMode;
use Carbon\CarbonInterface;

class ClaimSyncPolicy
{
    public const SUBJECT_WORKGROUPS = 'workgroups';

    public const SUBJECT_ROLES = 'roles';

    public const SUBJECT_CUSTODIANS = 'custodians';

    public const SUBJECTS = [self::SUBJECT_WORKGROUPS, self::SUBJECT_ROLES, self::SUBJECT_CUSTODIANS];

    public function editingMode(string $subject): MembershipMode
    {
        return match ($this->trustedModeOrNever($subject)) {
            ClaimTrust::FIRST_LOGIN => MembershipMode::FIRST_LOGIN,
            ClaimTrust::ALWAYS => $this->isAuthoritative($subject) ? MembershipMode::AUTHORITATIVE : MembershipMode::ADDITIVE,
            ClaimTrust::NEVER => MembershipMode::INTERNAL,
        };
    }

    public function shouldSync(string $subject, ?CarbonInterface $claimsSyncedAt): bool
    {
        return match ($this->trustedModeOrNever($subject)) {
            ClaimTrust::ALWAYS => true,
            ClaimTrust::FIRST_LOGIN => $claimsSyncedAt === null,
            ClaimTrust::NEVER => false,
        };
    }

    public function trustedModeOrNever(string $subject): ClaimTrust
    {
        $trust = config("claimsaccesscontrol.sync.{$subject}.trust");

        return (is_string($trust) ? ClaimTrust::tryFrom($trust) : null) ?? ClaimTrust::NEVER;
    }

    public function isAuthoritative(string $subject): bool
    {
        return $this->flag("claimsaccesscontrol.sync.{$subject}.authoritative", false);
    }

    public function shouldEnsureDefaultWorkgroup(): bool
    {
        return $this->flag('claimsaccesscontrol.sync.workgroups.ensure_default', true);
    }

    public function shouldEnsureDefaultRole(): bool
    {
        return $this->flag('claimsaccesscontrol.sync.roles.ensure_default', true);
    }

    public function shouldSyncSdeWorkgroupsFromClaim(): bool
    {
        return $this->flag('claimsaccesscontrol.sync.workgroups.sde_from_claim', true);
    }

    public function shouldApplyDefaultsOnCreate(): bool
    {
        return $this->flag('claimsaccesscontrol.sync.provision.defaults_on_create', true);
    }

    private function flag(string $key, bool $default): bool
    {
        return filter_var(config($key, $default), FILTER_VALIDATE_BOOLEAN);
    }
}
