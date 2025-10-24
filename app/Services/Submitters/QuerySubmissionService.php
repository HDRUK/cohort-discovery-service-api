<?php

namespace App\Services\Submitters;

use DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;

class QuerySubmissionService
{
    private $tag = 'QuerySubmissionService';

    public function __construct(
        protected Query $queryModel,
        protected Collection $collectionModel,
        protected Task $taskModel,
    ) {
    }

    public function handle(array $data, int $userId): array
    {
        // Wrapped in a transaction to ensure nothing is orphaned on failure
        // which could prevent the system from functioning properly when
        // this many moving parts are in play (Queries/Tasks/Observers and Jobs).
        //
        try {
            return DB::transaction(function () use ($data, $userId) {
                // Generate the query
                $query = Query::create([
                    'pid' => Str::uuid(),
                    'name' => $data['name'],
                    'definition' => $data['definition'],
                    'user_id' => $userId,
                ]);

                // Get relevant collections
                $collections = Collection::query()
                    ->when(!empty($data['collection_filter']), function ($q) use ($data) {
                        $q->whereIn('pid', $data['collection_filter']);
                    })
                    ->select(['id', 'type'])
                    ->get();

                // Create tasks
                $tasks = $collections->map(function ($collection) use ($query, $data) {
                    $task = Task::create([
                        'pid' => Str::uuid(),
                        'query_id' => $query->id,
                        'collection_id' => $collection->id,
                        'created_at' => Carbon::now(),
                        'task_type' => $data['task_type'],
                    ]);

                    // Offload side effects (job dispatching) to observers - TODO
                    return $task;
                });

                return [
                    'query_pid' => $query->pid,
                    'task_count' => $tasks->count(),
                    'task_pids' => $tasks->pluck('pid'),
                ];
            });
        } catch (\Throwable $e) {
            \Log::error($this->tag . ' - failed: ' . $e->getMessage());
            return [
                'query_pid' => null,
                'task_count' => null,
                'task_pids' => null,
            ];
        }
    }
}
