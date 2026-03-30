<?php

namespace App\Services\Collections;

use App\Exceptions\Errors_1xxx\CollectionPermissionsNotMetException as CollectionException;
use App\Models\Collection;
use App\Models\CustodianHasUser;
use App\Models\User;

class CollectionStateService
{
    /**
     * Determines if the incoming Collection can be transitions to $state by $user
     * based upon user roles assigned.
     *
     * Potentially needs padding out with other flows, this is the bear minimum to
     * ensure that custodians can only transition from Draft -> Pending, and internal
     * admins can transition from Pending -> Active -> Rejected
     *
     * Everything else is handled by zero-trust and rejected.
     */
    public function canUserTransition(Collection $collection, string $state, User $user)
    {
        $isCustodianUser = CustodianHasUser::query()
                ->where('custodian_id', $collection->custodian_id)
                ->where('user_id', $user->id)
                ->exists();

        switch (strtolower($state)) {
            case Collection::STATUS_PENDING:
                if ($isCustodianUser || $user->hasRole('admin')) {
                    return true;
                }

                return false;
            case Collection::STATUS_ACTIVE:
            case Collection::STATUS_REJECTED:
                if ($user->hasRole('admin')) {
                    return true;
                }

                return false;
            default:
                // Bit risky.
                return $collection->canTransitionTo($state);
        }
    }

    public function transition(Collection $collection, string $state, User $user): Collection
    {
        if (! $this->canUserTransition($collection, $state, $user)) {
            throw new CollectionException($state);
        }

        return $collection->transitionTo($state);
    }
}
