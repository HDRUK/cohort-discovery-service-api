<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        $query = $task->submittedQuery;
        return $query->user_id === $user->id;
    }
}
