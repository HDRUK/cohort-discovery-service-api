<?php

namespace App\Policies;

use App\Models\User;

class FeaturePolicy
{
    public function manage(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
