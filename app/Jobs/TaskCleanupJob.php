<?php

namespace App\Jobs;

use App\Enums\TaskType;
use App\Models\Task;
use App\Models\TaskRun;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TaskCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle()
    {
        $timeoutSecondsByType = [
            TaskType::A->value => config('tasks.a_type_timeout_seconds'),
            TaskType::B->value => config('tasks.b_type_timeout_seconds'),
        ];
        $now = Carbon::now();

        Task::query()
            ->whereNull('completed_at')
            ->where(function ($query) use ($now, $timeoutSecondsByType) {
                foreach ($timeoutSecondsByType as $taskType => $timeoutSeconds) {
                    $query->orWhere(fn ($q) => $q
                        ->where('task_type', $taskType)
                        ->where('created_at', '<', $now->copy()->subSeconds($timeoutSeconds)));
                }
            })
            ->chunkById(100, function ($tasks) use ($now, $timeoutSecondsByType) {
                foreach ($tasks as $t) {
                    //$task = Task::whereKey($t->id)->lockForUpdate()->first(); // temp disabled
                    $task = Task::whereKey($t->id)->first();
                    if (! $task || $task->completed_at) {
                        continue;
                    }

                    if ($task->leased_until && $task->leased_until->isFuture()) {
                        continue;
                    }

                    $timeout = CarbonInterval::seconds($timeoutSecondsByType[$task->task_type->value])
                        ->cascade()
                        ->forHumans();

                    $tr = TaskRun::updateOrCreate(
                        [
                            'task_id' => $task->id,
                            'attempt' => $task->attempts,
                        ],
                        [
                            'finished_at' => $now,
                            'error_class' => 'Timeout',
                            'error_message' => "No result received within {$timeout} ({$task->task_type->name}-type task).",
                            'claimed_at' => $task->started_at ?? $now,
                            'started_at' => $task->started_at ?? $now,
                        ]
                    );

                    \Log::info('timed out run', [
                        'task_id' => $task->id,
                        'attempt' => $task->attempts,
                        'task_run_id' => $tr->id,
                    ]);

                    $task->update([
                        'completed_at' => $now,
                        'failed_at' => $now,
                        'leased_until' => null,
                        'leased_by' => null,
                    ]);

                }
            });
    }
}
