<?php

namespace App\Services\TokenSync;

use App\Models\User;
use App\Services\Claims\ClaimResolver;
use App\Services\Claims\ClaimSyncPolicy;
use Carbon\CarbonInterface;

class WorkgroupSyncerService
{
    private array $nhsWorkgroups = ['NHS-SDE', 'UK-INDUSTRY', 'UK-RESEARCH'];

    public function __construct(
        private readonly ClaimResolver $resolver,
        private readonly ClaimSyncPolicy $policy,
    ) {
    }

    public function sync(
        User $user,
        array $tokenWorkgroups,
        bool $hasSdeApproval,
        ?CarbonInterface $claimsSyncedAt = null,
    ): bool {
        if (! $this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, $claimsSyncedAt)) {
            return false;
        }

        $finalIds = array_values(array_unique(array_merge(
            $this->defaultWorkgroupIds($hasSdeApproval),
            $this->resolver->workgroupIdsForClaimValues($tokenWorkgroups),
        )));

        if ($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_WORKGROUPS)) {
            $user->workgroups()->sync($finalIds);
        } else {
            $user->workgroups()->syncWithoutDetaching($finalIds);
        }

        return true;
    }

    private function defaultWorkgroupIds(bool $hasSdeApproval): array
    {
        $names = [];

        if ($this->policy->shouldEnsureDefaultWorkgroup()) {
            $names[] = 'DEFAULT';
        }

        if ($hasSdeApproval && $this->policy->shouldSyncSdeWorkgroupsFromClaim()) {
            $names = array_merge($names, $this->nhsWorkgroups);
        }

        return $this->resolver->workgroupIdsForNames($names);
    }
}
