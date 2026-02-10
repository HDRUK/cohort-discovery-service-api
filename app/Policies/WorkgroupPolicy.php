<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workgroup;

class WorkgroupPolicy
{
    public function access(User $user, Workgroup $workgroup): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        } else {
            return WorkgroupHasUser::where([
                'user_id' => $user->id,
                'workgroup_id' => $workgroup->id
            ])->exists();
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, Workgroup $workgroup): bool
    {
        return $this->access($user, $workgroup);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
