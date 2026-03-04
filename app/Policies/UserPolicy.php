<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function addToWorkgroup(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function removeFromWorkgroup(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
