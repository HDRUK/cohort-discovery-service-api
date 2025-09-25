<?php

namespace App\Policies;

use App\Models\Query;
use App\Models\User;

class QueryPolicy
{
    public function view(User $user, Query $query): bool
    {
        return $query->user_id === $user->id;
    }
}
