<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Custodian;
use App\Models\CollectionHost;
use App\Models\CustodianHasUser;

class CollectionHostPolicy
{
    public function access(User $user, CollectionHost $collectionHosts): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        } else {
            return CustodianHasUser::where([
                'user_id' => $user->id,
                'custodian_id' => $collectionHosts->custodian_id
            ])->exists();
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, CollectionHost $collectionHosts): bool
    {
        return $this->access($user, $collectionHosts);
    }

    public function create(User $user, Custodian $custodian): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        } else {
            return CustodianHasUser::where([
                'user_id' => $user->id,
                'custodian_id' => $custodian->id
            ])->exists();
        }
    }

    public function update(User $user, CollectionHost $collectionHosts): bool
    {
        return $this->access($user, $collectionHosts);
    }

    public function delete(User $user, CollectionHost $collectionHosts): bool
    {
        return $this->access($user, $collectionHosts);
    }
}
