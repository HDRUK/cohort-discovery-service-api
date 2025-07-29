<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\Query;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'pid' => Str::uuid(),
            'query_id' => Query::factory(),
            'collection_id' => Collection::factory(),
            'completed_at' => null,
            'created_at' => now(),
            'task_type' => 'a'
        ];
    }
}
