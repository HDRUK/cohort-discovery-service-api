<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Activity\ActivityLogger;
use Carbon\Carbon;
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

    public function handle(ActivityLogger $activityLogger)
    {
        $timeoutSeconds = (int) config('tasks.default_timeout_seconds', 60);
        $now = Carbon::now();
        $cutoff = $now->copy()->subSeconds($timeoutSeconds);

        Task::query()
            ->whereNull('completed_at')
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($tasks) use ($now, $timeoutSeconds, $activityLogger) {
                foreach ($tasks as $t) {
                    //$task = Task::whereKey($t->id)->lockForUpdate()->first(); // temp disabled
                    $task = Task::whereKey($t->id)->first();
                    if (! $task || $task->completed_at) {
                        return;
                    }

                    if ($task->leased_until && $task->leased_until->isFuture()) {
                        return;
                    }

                    $tr = TaskRun::updateOrCreate(
                        [
                            'task_id' => $task->id,
                            'attempt' => $task->attempts,
                        ],
                        [
                            'finished_at' => $now,
                            'error_class' => 'Timeout',
                            'error_message' => "No result received within {$timeoutSeconds} seconds.",
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

                    $activityLogger->custom('tasks', 'failed', $task, [
                        'task_run' => [
                            'id' => $tr->id,
                            'attempt' => $tr->attempt,
                        ],
                        'result' => [
                            'timeout_seconds' => $timeoutSeconds,
                            'completed_at' => $task->completed_at,
                            'failed_at' => $task->failed_at,
                        ],
                        'error' => [
                            'class' => 'Timeout',
                            'message' => "No result received within {$timeoutSeconds} seconds.",
                        ],
                    ], 'task_timed_out');
                }
            });
    }
}
