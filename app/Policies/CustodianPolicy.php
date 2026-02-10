<?php

namespace App\Policies;

use App\Models\Custodian;
use App\Models\CustodianHasUser;
use App\Models\User;

class CustodianPolicy
{
    /**
     * Determine whether the user can access the model.
     */
    public function access(User $user, Custodian $custodian): bool
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

    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, Custodian $custodian): bool
    {
        return $this->access($user, $custodian);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Custodian $custodian): bool
    {
        return $this->access($user, $custodian);
    }

    public function delete(User $user, Custodian $custodian): bool
    {
        return $user->hasRole('admin');
    }
}
