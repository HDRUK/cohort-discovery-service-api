<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Models\CustodianHasUser;

class TaskPolicy
{
    public function viewAdmin(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, Task $task): bool
    {
        if ($user->hasRole('admin')) {//admins can see all tasks
            return true;
        }

        $collection = $task->collection;

        $isCustodianAdmin = CustodianHasUser::where([
                'custodian_id' => $collection->custodian_id,
                'user_id' => $user->id
            ])->exists();

        if ($isCustodianAdmin) {//custodians can see tasks ran on their collections
            return true;
        }

        $query = $task->submittedQuery;

        return $query->user_id === $user->id;
    }
}
