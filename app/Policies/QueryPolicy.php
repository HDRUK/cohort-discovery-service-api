<?php

namespace App\Policies;

use App\Models\Query;
use App\Models\User;

class QueryPolicy
{

    public function access(User $user, Query $query): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        } else {
            return $query->user_id === $user->id;
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, Query $query): bool
    {
        return $this->access($user, $query);
    }
}
