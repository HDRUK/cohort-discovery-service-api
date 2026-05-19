<?php

namespace App\Services\RegressionTest;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Query;
use App\Models\RegressionTest;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RegressionTestService
{
    public function create(array $data, ?int $userId = null): RegressionTest
    {
        return DB::transaction(function () use ($data, $userId) {
            $query = Query::create([
                'name' => $data['name'],
                'definition' => $data['query_definition'],
                'user_id' => $userId,
            ]);

            $test = RegressionTest::create([
                'name' => $data['name'],
                'query_id' => $query->id,
                'user_id' => $userId,
            ]);

            $test->collections()->attach($this->buildPivotData($data['collections']));

            return $test->load(['queryDefinition', 'collections']);
        });
    }

    public function update(RegressionTest $test, array $data): RegressionTest
    {
        return DB::transaction(function () use ($test, $data) {
            $updatedName = $data['name'] ?? $test->name;

            if (isset($data['name'])) {
                $test->update(['name' => $data['name']]);
            }

            if (isset($data['query_definition'])) {
                $test->queryDefinition->update([
                    'definition' => $data['query_definition'],
                    'name' => $updatedName,
                ]);
            } elseif (isset($data['name'])) {
                $test->queryDefinition->update(['name' => $updatedName]);
            }

            if (isset($data['collections'])) {
                $test->collections()->sync($this->buildPivotData($data['collections']));
            }

            return $test->refresh()->load(['queryDefinition', 'collections']);
        });
    }

    public function run(RegressionTest $test): array
    {
        $test->loadMissing('collections');

        $tasks = $test->collections->map(fn (Collection $collection) => Task::create([
            'query_id' => $test->query_id,
            'collection_id' => $collection->id,
            'created_at' => Carbon::now(),
            'task_type' => TaskType::A,
        ]));

        return [
            'task_count' => $tasks->count(),
            'task_pids' => $tasks->pluck('pid'),
        ];
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, RegressionTest> $tests */
    public function listWithStats(\Illuminate\Database\Eloquent\Collection $tests): array
    {
        if ($tests->isEmpty()) {
            return [];
        }

        $queryIds = $tests->pluck('query_id')->unique();

        $tasksByKey = Task::with('result')
            ->whereIn('query_id', $queryIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy(fn ($t) => $t->query_id.'_'.$t->collection_id);

        return $tests->map(fn ($test) => $this->formatTestWithStats($test, $tasksByKey))->all();
    }

    public function getWithStats(RegressionTest $test): array
    {
        $test->loadMissing('collections');

        $tasksByKey = Task::with('result')
            ->where('query_id', $test->query_id)
            ->whereIn('collection_id', $test->collections->pluck('id'))
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy(fn ($t) => $t->query_id.'_'.$t->collection_id);

        return $this->formatTestWithStats($test, $tasksByKey);
    }

    private function formatTestWithStats(RegressionTest $test, \Illuminate\Support\Collection $tasksByKey): array
    {
        $collections = $test->collections->map(function (Collection $collection) use ($test, $tasksByKey) {
            $key = $test->query_id.'_'.$collection->id;
            $tasks = $tasksByKey->get($key, collect());
            $latestTask = $tasks->first();
            $completedTasks = $tasks->filter(fn ($t) => $t->completed_at !== null);
            $expected = $collection->regressionTestPivot->expected_result;

            $passCount = $completedTasks->filter(
                fn ($t) => $t->result && $t->result->count == $expected
            )->count();

            return [
                'pid' => $collection->pid,
                'name' => $collection->name,
                'expected_result' => $expected,
                'run_count' => $tasks->count(),
                'last_run_at' => $latestTask?->created_at,
                'pass_rate' => $completedTasks->count() > 0
                    ? round($passCount / $completedTasks->count() * 100, 1)
                    : null,
                'last_passed' => $latestTask && $latestTask->result
                    ? $latestTask->result->count == $expected
                    : null,
                'tasks' => $tasks->map(fn ($t) => [
                    'pid' => $t->pid,
                    'created_at' => $t->created_at,
                    'completed_at' => $t->completed_at,
                    'failed_at' => $t->failed_at,
                    'result' => $t->result ? ['count' => $t->result->count, 'status' => $t->result->status] : null,
                ])->values(),
            ];
        });

        return [
            'id' => $test->id,
            'pid' => $test->pid,
            'name' => $test->name,
            'created_at' => $test->created_at,
            'updated_at' => $test->updated_at,
            'query' => $test->queryDefinition ? [
                'pid' => $test->queryDefinition->pid,
                'name' => $test->queryDefinition->name,
                'definition' => $test->queryDefinition->definition,
            ] : null,
            'collections' => $collections->values(),
        ];
    }

    private function buildPivotData(array $collectionsInput): array
    {
        $pids = collect($collectionsInput)->pluck('pid');
        $collectionsByPid = Collection::whereIn('pid', $pids)->get()->keyBy('pid');

        return collect($collectionsInput)->mapWithKeys(fn ($c) => [
            $collectionsByPid[$c['pid']]->id => ['expected_result' => $c['expected_result'] ?? null],
        ])->toArray();
    }
}
