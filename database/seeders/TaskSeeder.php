<?php

namespace Database\Seeders;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $query = Query::where('name', 'example-1')->first();
        $collections = Collection::all()->pluck('id');

        foreach ($collections as $collectionId) {
            Task::create([
                'query_id' => $query->id,
                'collection_id' => $collectionId,
                'created_at' => now(),
                'completed_at' => null,
            ]);
        }
    }
}
