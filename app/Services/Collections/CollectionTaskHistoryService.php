<?php

namespace App\Services\Collections;

use App\Enums\TaskType;
use App\Models\Query;
use App\Models\Task;
use App\Models\TaskRun;
use App\Support\TimeWindow;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type TaskRunShape array{attempt: int, worker_id: string|null, claimed_at: string|null, started_at: string|null, finished_at: string|null, duration_ms: int|null, result_status: string|null, error_class: string|null, error_message: string|null}
 * @phpstan-type TaskQueryShape array{pid: string, name: string|null, query_type: string|null, definition?: array<string, mixed>|null}
 */
class CollectionTaskHistoryService
{
    public const DEFAULT_WINDOW = '1d';

    public const STATUSES = ['succeeded', 'failed', 'in_flight', 'pending'];

    /**
     * @return array{from: Carbon, to: Carbon}
     *
     * @throws ValidationException
     */
    public function resolveRange(array $params): array
    {
        $to = isset($params['to']) ? Carbon::parse($params['to'])->utc() : Carbon::now();

        $from = isset($params['from'])
            ? Carbon::parse($params['from'])->utc()
            : TimeWindow::subtract($to, $params['window'] ?? self::DEFAULT_WINDOW);

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'from' => 'The from field must not be later than to.',
            ]);
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $range
     * @param  array{task_type?: string|null, status?: string|null}  $filters
     */
    public function history(
        int $collectionId,
        array $range,
        array $filters,
        int $perPage,
        bool $includeDefinition = false
    ): array {
        return [
            'collection_id' => $collectionId,
            'from' => $range['from']->toIso8601ZuluString(),
            'to' => $range['to']->toIso8601ZuluString(),
            'summary' => $this->summary($collectionId, $range, $filters),
            'tasks' => $this->tasks($collectionId, $range, $filters, $perPage, $includeDefinition),
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $range
     * @param  array{task_type?: string|null, status?: string|null}  $filters
     */
    private function tasks(
        int $collectionId,
        array $range,
        array $filters,
        int $perPage,
        bool $includeDefinition
    ): LengthAwarePaginator {
        $paginator = Task::query()
            ->where('collection_id', $collectionId)
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->when(
                $filters['task_type'] ?? null,
                fn ($query, $taskType) => $query->where('task_type', $taskType)
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, $status) => $query->whereRaw($this->statusPredicate($status, ''))
            )
            ->with([
                'submittedQuery:id,pid,name,query_type'.($includeDefinition ? ',definition' : ''),
                'runs' => fn ($query) => $query->orderBy('attempt'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $paginator->through(fn (Task $task) => $this->presentTask($task, $includeDefinition));
    }

    /**
     * @return array{
     *     pid: string,
     *     task_type: string,
     *     status: string,
     *     attempts: int,
     *     created_at: string,
     *     attempted_at: string|null,
     *     completed_at: string|null,
     *     failed_at: string|null,
     *     queued_for_ms: int|null,
     *     duration_ms: int|null,
     *     total_duration_ms: int|null,
     *     query: TaskQueryShape|null,
     *     runs: list<TaskRunShape>
     * }
     */
    private function presentTask(Task $task, bool $includeDefinition): array
    {
        $runs = $task->runs;
        $firstClaim = $runs->min('claimed_at');
        $lastRun = $runs->last();
        $measured = $runs->whereNotNull('duration_ms');

        return [
            'pid' => $task->pid,
            'task_type' => $task->task_type->value,
            'status' => $this->taskStatus($task),
            'attempts' => (int) $task->attempts,
            'created_at' => $task->created_at->toIso8601ZuluString(),
            'attempted_at' => $task->attempted_at?->toIso8601ZuluString(),
            'completed_at' => $task->completed_at?->toIso8601ZuluString(),
            'failed_at' => $task->failed_at?->toIso8601ZuluString(),
            'queued_for_ms' => $firstClaim
                ? max(0, (int) $task->created_at->diffInMilliseconds($firstClaim))
                : null,
            'duration_ms' => $lastRun !== null && $lastRun->duration_ms !== null
                ? (int) $lastRun->duration_ms
                : null,
            'total_duration_ms' => $measured->isEmpty() ? null : (int) $measured->sum('duration_ms'),
            'query' => $task->submittedQuery
                ? $this->presentQuery($task->submittedQuery, $includeDefinition)
                : null,
            'runs' => $runs->map(fn (TaskRun $run) => [
                'attempt' => (int) $run->attempt,
                'worker_id' => $run->worker_id,
                'claimed_at' => $run->claimed_at?->toIso8601ZuluString(),
                'started_at' => $run->started_at?->toIso8601ZuluString(),
                'finished_at' => $run->finished_at?->toIso8601ZuluString(),
                'duration_ms' => $run->duration_ms !== null ? (int) $run->duration_ms : null,
                'result_status' => $run->result_status,
                'error_class' => $run->error_class,
                'error_message' => $run->error_message,
            ])->values()->all(),
        ];
    }

    /**
     * @return TaskQueryShape
     */
    private function presentQuery(Query $query, bool $includeDefinition): array
    {
        $presented = [
            'pid' => $query->pid,
            'name' => $query->name,
            'query_type' => $query->query_type,
        ];

        if ($includeDefinition) {
            $presented['definition'] = $query->definition;
        }

        return $presented;
    }

    private function taskStatus(Task $task): string
    {
        return match (true) {
            $task->failed_at !== null => 'failed',
            $task->completed_at !== null => 'succeeded',
            $task->attempted_at !== null => 'in_flight',
            default => 'pending',
        };
    }

    private function statusPredicate(string $status, string $prefix): string
    {
        return match ($status) {
            'failed' => "{$prefix}failed_at IS NOT NULL",
            'succeeded' => "{$prefix}failed_at IS NULL AND {$prefix}completed_at IS NOT NULL",
            'in_flight' => "{$prefix}failed_at IS NULL AND {$prefix}completed_at IS NULL AND {$prefix}attempted_at IS NOT NULL",
            default => "{$prefix}failed_at IS NULL AND {$prefix}completed_at IS NULL AND {$prefix}attempted_at IS NULL",
        };
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $range
     * @param  array{task_type?: string|null, status?: string|null}  $filters
     */
    private function summary(int $collectionId, array $range, array $filters): array
    {
        $counts = $this->countsOverRange($collectionId, $range, $filters);
        $durations = $this->durationsOverRange($collectionId, $range, $filters);

        return $counts + ['duration_ms' => $durations];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $range
     * @param  array{task_type?: string|null, status?: string|null}  $filters
     */
    private function countsOverRange(int $collectionId, array $range, array $filters): array
    {
        [$where, $bindings] = $this->scope($collectionId, $range, $filters, '');

        $statusSelects = [];
        foreach (self::STATUSES as $status) {
            $statusSelects[] = 'SUM(('.$this->statusPredicate($status, '').")) AS {$status}";
        }

        $typeSelects = [];
        foreach (TaskType::cases() as $taskType) {
            $typeSelects[] = "SUM(task_type = '{$taskType->value}') AS type_{$taskType->value}";
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) AS tasks, '
            .implode(', ', $statusSelects).', '
            .implode(', ', $typeSelects).', '
            .'COALESCE(SUM(attempts), 0) AS total_attempts,
               COALESCE(SUM(attempts > 1), 0) AS retried_tasks,
               COALESCE(MAX(attempts), 0) AS max_attempts
             FROM tasks
             WHERE '.$where,
            $bindings
        );

        $taskTypes = [];
        foreach (TaskType::cases() as $taskType) {
            $taskTypes[$taskType->value] = (int) $row->{'type_'.$taskType->value};
        }

        $summary = ['tasks' => (int) $row->tasks];

        foreach (self::STATUSES as $status) {
            $summary[$status] = (int) $row->{$status};
        }

        return $summary + [
            'task_types' => $taskTypes,
            'attempts' => [
                'total' => (int) $row->total_attempts,
                'retried_tasks' => (int) $row->retried_tasks,
                'max' => (int) $row->max_attempts,
            ],
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $range
     * @param  array{task_type?: string|null, status?: string|null}  $filters
     */
    private function durationsOverRange(int $collectionId, array $range, array $filters): array
    {
        [$where, $bindings] = $this->scope($collectionId, $range, $filters, 'tasks.');

        $row = DB::selectOne(
            'SELECT
                COUNT(*) AS runs_measured,
                MIN(duration_ms) AS min_ms,
                ROUND(AVG(duration_ms)) AS avg_ms,
                MAX(duration_ms) AS max_ms,
                MIN(CASE WHEN cd >= 0.50 THEN duration_ms END) AS p50_ms,
                MIN(CASE WHEN cd >= 0.95 THEN duration_ms END) AS p95_ms
             FROM (
                SELECT
                    task_runs.duration_ms,
                    CUME_DIST() OVER (ORDER BY task_runs.duration_ms) AS cd
                FROM task_runs
                INNER JOIN tasks ON tasks.id = task_runs.task_id
                WHERE '.$where.' AND task_runs.duration_ms IS NOT NULL
             ) AS measured_runs',
            $bindings
        );

        return [
            'runs_measured' => (int) $row->runs_measured,
            'min' => $row->min_ms !== null ? (int) $row->min_ms : null,
            'avg' => $row->avg_ms !== null ? (int) $row->avg_ms : null,
            'p50' => $row->p50_ms !== null ? (int) $row->p50_ms : null,
            'p95' => $row->p95_ms !== null ? (int) $row->p95_ms : null,
            'max' => $row->max_ms !== null ? (int) $row->max_ms : null,
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $range
     * @param  array{task_type?: string|null, status?: string|null}  $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function scope(int $collectionId, array $range, array $filters, string $prefix): array
    {
        $conditions = [
            "{$prefix}collection_id = ?",
            "{$prefix}created_at >= ?",
            "{$prefix}created_at <= ?",
        ];

        $bindings = [
            $collectionId,
            $range['from']->toDateTimeString(),
            $range['to']->toDateTimeString(),
        ];

        if (! empty($filters['task_type'])) {
            $conditions[] = "{$prefix}task_type = ?";
            $bindings[] = $filters['task_type'];
        }

        if (! empty($filters['status'])) {
            $conditions[] = '('.$this->statusPredicate($filters['status'], $prefix).')';
        }

        return [implode(' AND ', $conditions), $bindings];
    }
}
