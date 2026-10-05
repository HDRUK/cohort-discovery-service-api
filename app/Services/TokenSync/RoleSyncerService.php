<?php

namespace App\Services\TokenSync;

use App\Models\User;
use App\Services\Claims\ClaimResolver;
use App\Services\Claims\ClaimSyncPolicy;
use Carbon\CarbonInterface;

class RoleSyncerService
{
    public function __construct(
        private readonly ClaimResolver $resolver,
        private readonly ClaimSyncPolicy $policy,
    ) {
    }

    public function sync(
        User $user,
        array $roleNames,
        ?CarbonInterface $claimsSyncedAt = null,
    ): bool {
        if (! $this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_ROLES, $claimsSyncedAt)) {
            return false;
        }

        $roleIds = $this->resolver->roleIdsForClaimValues($roleNames);

        if ($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_ROLES)) {
            $user->roles()->sync($roleIds);
        } else {
            $user->roles()->syncWithoutDetaching($roleIds);
        }

        return true;
    }
}
