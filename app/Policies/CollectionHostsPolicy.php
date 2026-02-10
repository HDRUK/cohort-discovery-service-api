<?php

namespace App\Policies;

use App\Models\User;

class CollectionHostsPolicy
{
    public function access(User $user, CollectionHosts $collectionHosts): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        } else {
            return CollectionHostsHasUser::where([
                'user_id' => $user->id,
                'collection_hosts_id' => $collectionHosts->id
            ])->exists();
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, CollectionHosts $collectionHosts): bool
    {
        return $this->access($user, $collectionHosts);
    }

    public function create(User $user, CollectionHosts $collectionHosts): bool
    {
        return $this->access($user, $collectionHosts);
    }

    public function update(User $user, CollectionHosts $collectionHosts): bool
    {
        return $this->access($user, $collectionHosts);
    }

    public function delete(User $user, CollectionHosts $collectionHosts): bool
    {
        return $this->access($user, $collectionHosts);
    }
}
