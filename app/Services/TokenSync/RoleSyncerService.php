<?php

namespace App\Services\TokenSync;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Claims\ClaimResolver;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\Claims\RoleSyncFloor;
use Carbon\CarbonInterface;

class RoleSyncerService
{
    public function __construct(
        private readonly ActivityLogger $activity,
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

        $authoritative = $this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_ROLES);

        $changes = $authoritative
            ? $user->roles()->sync($roleIds)
            : $user->roles()->syncWithoutDetaching($roleIds);

        $this->activity->membershipChanged(
            'claims',
            'claims_synced',
            $user,
            'roles',
            $changes,
            $authoritative,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_ROLES),
            'claims_synced',
        );

        return true;
    }
}
