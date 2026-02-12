<?php

namespace App\Policies;

use App\Models\Collection;
use App\Models\User;
use App\Models\Custodian;
use App\Models\CustodianHasUser;

class CollectionPolicy
{
    public function access(User $user, Collection $collection): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        } else {
            return CustodianHasUser::where([
                'custodian_id' => $collection->custodian_id,
                'user_id' => $user->id
            ])->exists();
        }
    }

    public function viewAny(User $user, Collection $collection): bool
    {
        return $this->access($user, $collection);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Collection $collection): bool
    {
        return $this->access($user, $collection);
    }

    public function create(User $user, Custodian $custodian): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        } else {
            return CustodianHasUser::where([
                'custodian_id' => $custodian->id,
                'user_id' => $user->id
            ])->exists();
        }
    }

    public function update(User $user, Collection $collection): bool
    {
        $custodian = $collection->custodian;
        if ($user->hasRole('admin')) {
            return true;
        } else {
            return CustodianHasUser::where([
                'custodian_id' => $custodian->id,
                'user_id' => $user->id
            ])->exists();
        }
    }

    public function delete(User $user, Collection $collection): bool
    {
        return $this->access($user, $collection);
    }
}
