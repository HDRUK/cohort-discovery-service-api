<?php

namespace App\Observers;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;

class CollectionObserver
{
    public function created(Collection $collection)
    {
        $query = Query::create([
            'name' => 'initial-distribution-job-' . $collection->name,
            'definition' => [
                'code' => 'DEMOGRAPHICS',
            ],
        ]);

        Task::create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
            'created_at' => now(),
            'task_type' => TaskType::B
        ]);
    }
}
