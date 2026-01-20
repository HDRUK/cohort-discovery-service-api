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
            return CustodianHasUser::where(['user_id' => $user->id,'custodian_id' => $custodian->id])->exists();
        }
    }


}
