<?php

namespace App\Policies;

use App\Models\RegressionTest;
use App\Models\User;

class RegressionTestPolicy
{
    public function viewAnyForAdmin(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, RegressionTest $test): bool
    {
        return $user->hasRole('admin');
        // Future: || $test->user_id === $user->id
    }

    public function update(User $user, RegressionTest $test): bool
    {
        return $user->hasRole('admin');
        // Future: || $test->user_id === $user->id
    }

    public function delete(User $user, RegressionTest $test): bool
    {
        return $user->hasRole('admin');
        // Future: || $test->user_id === $user->id
    }

    public function run(User $user, RegressionTest $test): bool
    {
        return $user->hasRole('admin');
        // Future: || $test->user_id === $user->id
    }

    // public function viewAny(User $user): bool
    // {
    //     Future: scope to tests belonging to the authenticated user,
    //     restricted to users linked via CustodianHasUser:
    //     return CustodianHasUser::where('user_id', $user->id)->exists();
    // }

    // public function createForUser(User $user): bool
    // {
    //     Future: allow custodian users to create regression tests scoped to themselves.
    //     return CustodianHasUser::where('user_id', $user->id)->exists();
    // }
}
