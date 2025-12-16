<?php

namespace App\Observers;

use App\Enums\QueryType;
use App\Enums\TaskType;
use App\Jobs\RunBeaconTask;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Services\QueryContext\QueryContextType;
use Illuminate\Support\Str;

class CollectionObserver
{
    public function created(Collection $collection)
    {
        $query = Query::create([
            'name' => 'initial-distribution-job-'.$collection->name,
            'definition' => [
                'code' => QueryType::DEMOGRAPHICS->value,
            ],
            'query_type' => QueryType::DEMOGRAPHICS->value
        ]);

        $task = Task::create([
            'pid' => Str::uuid(),
            'query_id' => $query->id,
            'collection_id' => $collection->id,
            'created_at' => now(),
            'task_type' => TaskType::B,
        ]);

        $type = $collection->type;
        if ($type === QueryContextType::Beacon) {
            RunBeaconTask::dispatch($task);
        }

        $query = Query::create([
            'name' => 'initial-omop-concept-job-'.$collection->name,
            'definition' => [
                'code' => QueryType::GENERIC->value,
            ],
            'query_type' => QueryType::GENERIC->value
        ]);

        $task = Task::create([
            'pid' => Str::uuid(),
            'query_id' => $query->id,
            'collection_id' => $collection->id,
            'created_at' => now(),
            'task_type' => TaskType::B,
        ]);

        $type = $collection->type;
        if ($type === QueryContextType::Beacon) {
            // to be implemented..
            // RunBeaconTask::dispatch($task);
        }

        $collection->setState(Collection::STATUS_DRAFT);
    }
}
