<?php

namespace App\Traits;

use App\Enums\QueryType;
use App\Enums\TaskType;
use App\Models\Query;
use App\Models\Task;
use Carbon\Carbon;
use Str;

trait JobCreation
{
    public function createQuery(string $name, QueryType $type): Query
    {
        return Query::create([
            'pid' => Str::uuid(),
            'name' => $name,
            'definition' => [
                'code' => $type->value,
            ],
        ]);
    }

    public function createTask(Query $query, int $collectionId, TaskType $type): Task
    {
        return Task::create([
            'pid' => Str::uuid(),
            'query_id' => $query->id,
            'collection_id' => $collectionId,
            'created_at' => Carbon::now(),
            'task_type' => $type->value,
        ]);
    }
}
