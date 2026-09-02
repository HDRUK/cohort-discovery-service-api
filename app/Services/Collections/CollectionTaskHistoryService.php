<?php

namespace App\Services\Collections;

use App\Enums\TaskType;
use App\Models\Task;
use App\Support\TimeWindow;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Execution history for one collection: the tasks queued against it over a time
 * range, every attempt made at each, and how long those attempts took.
 *
 * Two reads of the same range. The page is Eloquent, because a task with its runs
 * is exactly the relationship Eloquent is good at. The summary is raw SQL, because
 * it has to describe the whole range rather than the page - loading every task to
 * total them in PHP would defeat the point of paginating.
 */
class CollectionTaskHistoryService
{
    public const DEFAULT_WINDOW = '1d';

    /**
     * Task-level outcomes, derived from the task's own timestamps rather than from
     * task_runs.result_status - a run timed out by TaskCleanupJob never gets a
     * result_status, so the task columns are the only complete signal.
     */
    public const STATUSES = ['succeeded', 'failed', 'in_flight', 'pending'];

    /**
     * Turn the request's window/from/to into a concrete range.
     *
     * Both ends are inclusive: this is a browsing endpoint, so `to=now` should
     * return the task that was just queued rather than narrowly miss it.
     *
     * Throws ValidationException so callers can invoke this alongside
     * $request->validate() and get a 422 rather than a 500.
     *
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
    public function history(int $collectionId, array $range, array $filters, int $perPage): array
    {
        return [
            'collection_id' => $collectionId,
            'from' => $range['from']->toIso8601ZuluString(),
            'to' => $range['to']->toIso8601ZuluString(),
            'summary' => $this->summary($collectionId, $range, $filters),
            'tasks' => $this->tasks($collectionId, $range, $filters, $perPage),
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $range
     * @param  array{task_type?: string|null, status?: string|null}  $filters
     */
    private function tasks(int $collectionId, array $range, array $filters, int $perPage): LengthAwarePaginator
    {
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
                // id is needed for the belongsTo to resolve; a soft-deleted query
                // leaves the relation null, which presentTask allows for.
                'submittedQuery:id,pid,name,query_type',
                'runs' => fn ($query) => $query->orderBy('attempt'),
            ])
            // created_at is second-granular and a burst can share one second, so the
            // id tiebreaker is what stops rows shifting between pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $paginator->through(fn (Task $task) => $this->presentTask($task));
    }

    private function presentTask(Task $task): array
    {
        $runs = $task->runs;
        $firstClaim = $runs->min('claimed_at');
        $lastRun = $runs->last();
        $measured = $runs->whereNotNull('duration_ms');

        return [
            'pid' => $task->pid,
            'task_type' => $task->task_type?->value,
            'status' => $this->taskStatus($task),
            'attempts' => (int) $task->attempts,
            'created_at' => $task->created_at?->toIso8601ZuluString(),
            'attempted_at' => $task->attempted_at?->toIso8601ZuluString(),
            'completed_at' => $task->completed_at?->toIso8601ZuluString(),
            'failed_at' => $task->failed_at?->toIso8601ZuluString(),
            // How long the work sat before a host took it - queue latency rather
            // than execution time, and the number that moves when a host is down.
            'queued_for_ms' => $task->created_at && $firstClaim
                ? max(0, (int) $task->created_at->diffInMilliseconds($firstClaim))
                : null,
            // The attempt that settled the task. Null while a run is still open, or
            // when it was timed out without ever reporting.
            'duration_ms' => $lastRun?->duration_ms !== null ? (int) $lastRun->duration_ms : null,
            // Time spent across every attempt, so a task retried twice is not
            // reported as being as cheap as its final run.
            'total_duration_ms' => $measured->isEmpty() ? null : (int) $measured->sum('duration_ms'),
            'query' => $task->submittedQuery ? [
                'pid' => $task->submittedQuery->pid,
                'name' => $task->submittedQuery->name,
                'query_type' => $task->submittedQuery->query_type,
            ] : null,
            'runs' => $runs->map(fn ($run) => [
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

    private function taskStatus(Task $task): string
    {
        // failed_at is checked first because a failure sets completed_at too.
        return match (true) {
            $task->failed_at !== null => 'failed',
            $task->completed_at !== null => 'succeeded',
            $task->attempted_at !== null => 'in_flight',
            default => 'pending',
        };
    }

    /**
     * The one definition of each status, in SQL, shared by the paginated list and
     * both summary aggregates so a filtered page and its summary cannot disagree.
     *
     * $prefix qualifies the columns for the joined duration query. Statuses reach
     * here only after Rule::in validation, so nothing user-supplied is interpolated.
     */
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

        // Driven off the enum so a new task type shows up here rather than being
        // silently absent from the breakdown.
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
     * Run-duration distribution across the range.
     *
     * Percentiles use CUME_DIST rather than an average alone: a collection host's
     * tail is what makes a query feel slow, and one 40-second outlier barely moves
     * the mean. MySQL has no percentile aggregate, so the nearest-rank value is
     * picked as the smallest duration whose cumulative distribution reaches p.
     *
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
            // Runs that reported a duration - fewer than the attempts made, because
            // an abandoned or timed-out attempt never reports one.
            'runs_measured' => (int) $row->runs_measured,
            'min' => $row->min_ms !== null ? (int) $row->min_ms : null,
            'avg' => $row->avg_ms !== null ? (int) $row->avg_ms : null,
            'p50' => $row->p50_ms !== null ? (int) $row->p50_ms : null,
            'p95' => $row->p95_ms !== null ? (int) $row->p95_ms : null,
            'max' => $row->max_ms !== null ? (int) $row->max_ms : null,
        ];
    }

    /**
     * The shared WHERE fragment for the summary aggregates, so they select over
     * exactly the rows the paginated list does.
     *
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
