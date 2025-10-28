<?php

namespace App\Observers;

use App\Models\Task;
use App\Enums\QueryContextType;

class TaskObserver
{
    public function created(Task $task): void
    {
        // Stubbed for recieving offloads from service.
        //
        // $collection = $task->collection;

        // if ($collection && $collection->type === QueryContextType::Beacon) {
        //     // Dispatch task context
        // };
    }
}
