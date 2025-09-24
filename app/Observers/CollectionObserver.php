<?php

namespace App\Observers;

use App\Enums\TaskType;
use App\Jobs\RunBeaconTask;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Services\QueryContext\QueryContextType;

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

        $task = Task::create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
            'created_at' => now(),
            'task_type' => TaskType::B
        ]);

        $type = $collection->type;
        if ($type === QueryContextType::Beacon) {
            RunBeaconTask::dispatch($task);
        }


        $query = Query::create([
            'name' => 'initial-omop-concept-job-' . $collection->name,
            'definition' => [
                'code' => 'GENERIC',
            ],
        ]);

        $task = Task::create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
            'created_at' => now(),
            'task_type' => TaskType::B
        ]);

        $type = $collection->type;
        if ($type === QueryContextType::Beacon) {
            // to be implemented..
            //RunBeaconTask::dispatch($task);
        }
    }
}
