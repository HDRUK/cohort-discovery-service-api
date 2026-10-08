<?php

namespace App\Services\TokenSync;

use App\Models\User;
use App\Services\Claims\ClaimResolver;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\Claims\RoleSyncFloor;
use Carbon\CarbonInterface;

class RoleSyncerService
{
    public function __construct(
        private readonly ClaimResolver $resolver,
        private readonly ClaimSyncPolicy $policy,
        private readonly RoleSyncFloor $roleFloor,
    ) {
    }

    public function sync(
        User $user,
        ?array $roleNames,
        ?CarbonInterface $claimsSyncedAt = null,
    ): bool {
        if ($roleNames === null) {
            return false;
        }

        if (! $this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_ROLES, $claimsSyncedAt)) {
            return false;
        }

        $roleIds = $this->roleFloor->apply(
            $user,
            $this->resolver->roleIdsForClaimValues($roleNames)
        );

        if ($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_ROLES)) {
            $user->roles()->sync($roleIds);
        } else {
            $user->roles()->syncWithoutDetaching($roleIds);
        }

        return true;
    }
}
