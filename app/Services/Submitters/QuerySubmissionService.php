<?php

namespace App\Services\Submitters;

use App\Enums\MissingDataTable;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Support\QueryDefinitionInspector;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class QuerySubmissionService
{
    private $tag = 'QuerySubmissionService';

    public function __construct(
        protected Query $queryModel,
        protected Collection $collectionModel,
        protected Task $taskModel,
        protected TaskFailureRecorder $taskFailureRecorder,
        protected QueryDefinitionInspector $inspector,
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

                if (is_null($data['name'])) {
                    $query->update(['name' => $query->pid]);
                }

                // Get relevant collections
                $collections = Collection::query()
                    ->when(! empty($data['collection_filter']), function ($q) use ($data) {
                        $q->whereIn('pid', $data['collection_filter']);
                    })
                    ->select(['id', 'type', 'location_enabled', 'death_enabled'])
                    ->get();

                // Determine, once, whether this query targets the location/death
                // tables, and whether each is enabled globally via feature flag.
                $categories = $this->inspector->categoriesUsed($data['definition']);
                $usesLocation = in_array(MissingDataTable::Location->value, $categories, true)
                    || $this->inspector->usesDemographicLocation($data['definition']);
                $usesDeath = in_array(MissingDataTable::Death->value, $categories, true);
                $locationFeatureOn = Feature::active('query-builder-use-location');
                $deathFeatureOn = Feature::active('query-builder-use-death');

                // Create tasks
                $tasks = $collections->map(function ($collection) use (
                    $query,
                    $data,
                    $usesLocation,
                    $usesDeath,
                    $locationFeatureOn,
                    $deathFeatureOn
                ) {
                    $task = Task::create([
                        'pid' => Str::uuid(),
                        'query_id' => $query->id,
                        'collection_id' => $collection->id,
                        'created_at' => Carbon::now(),
                        'task_type' => $data['task_type'],
                    ]);

                    // Block the task for this collection when it queries a table
                    // that is disabled globally (feature off) or missing for this
                    // collection (flag off) - failing it with a clear reason.
                    $reasons = [];
                    if ($usesLocation && (! $locationFeatureOn || ! $collection->location_enabled)) {
                        $reasons[] = MissingDataTable::Location->reason();
                    }
                    if ($usesDeath && (! $deathFeatureOn || ! $collection->death_enabled)) {
                        $reasons[] = MissingDataTable::Death->reason();
                    }
                    if (! empty($reasons)) {
                        $this->taskFailureRecorder->failWithReasons($task, $reasons);
                    }

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
            \Log::error($this->tag.' - failed: '.$e->getMessage());

            return [
                'query_pid' => null,
                'task_count' => null,
                'task_pids' => null,
            ];
        }
    }
}
