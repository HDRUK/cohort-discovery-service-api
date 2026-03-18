<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Models\CustodianHasUser;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        if ($user->hasRole('admin')) {//admins can see all tasks
            return true;
        }

        $query = $task->submittedQuery;
        $collection = $task->collection;

        $isCustodianAdmin = CustodianHasUser::where([
                'custodian_id' => $collection->custodian_id,
                'user_id' => $user->id
            ])->exists();
        if ($isCustodianAdmin) {//custodians can see tasks ran on their collections
            return true;
        }

        return $query->user_id === $user->id;
    }
}
