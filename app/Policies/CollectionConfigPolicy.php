<?php

namespace App\Policies;

use App\Models\Collection;
use App\Models\CollectionConfig;
use App\Models\CustodianHasUser;
use App\Models\User;

class CollectionConfigPolicy
{
    private function isCustodianUser(User $user, int $custodianId): bool
    {
        return CustodianHasUser::where([
            'user_id' => $user->id,
            'custodian_id' => $custodianId,
        ])->exists();
    }

    public function create(User $user, Collection $collection): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $this->isCustodianUser($user, $collection->custodian_id);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, CollectionConfig $collectionConfig): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $this->isCustodianUser($user, $collectionConfig->collection->custodian_id);
    }

    public function update(User $user, CollectionConfig $collectionConfig): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $this->isCustodianUser($user, $collectionConfig->collection->custodian_id);
    }

    public function delete(User $user, CollectionConfig $collectionConfig): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $this->isCustodianUser($user, $collectionConfig->collection->custodian_id);
    }
}
