<?php

namespace App\Services\Submitters;

use App\Models\Result;
use App\Models\Task;
use App\Models\TaskRun;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Enums\MissingDataTable;

class TaskFailureRecorder
{
    /**
     * Fail a task with one or more human-readable reasons, storing them the
     * same way a worker-reported error is stored: a failed TaskRun (carrying
     * the technical error_class/error_message) plus a Result (status + message)
     * and the task's failed_at/completed_at timestamps. This ensures the reason
     * surfaces on the Collection Results page exactly like a real BUNNY error.
     *
     * @param  array<int, string>  $reasons
     */
    public function failWithReasons(
        Task $task,
        array $reasons,
        string $errorClass = 'MissingRequiredTable'
    ): void {
        $message = implode('; ', $reasons);
        $finishedAt = Carbon::now();
        $missingReasons = [MissingDataTable::Location->reason(), MissingDataTable::Death->reason()];
        $missingData = array_intersect($missingReasons, $reasons);
        $resultStatus = !empty($missingData) ? 'missing' : 'failed';

        TaskRun::create([
            'task_id' => $task->id,
            'attempt' => 1,
            'worker_id' => 'system',
            'finished_at' => $finishedAt,
            'result_status' => $resultStatus,
            'error_class' => $errorClass,
            'error_message' => mb_strimwidth($message, 0, 2000, '…'),
        ]);

        // Set the pid explicitly rather than relying on Result's model-event
        // hook, so recording a failure does not depend on observers being active.
        $result = new Result([
            'task_id' => $task->id,
            'count' => 0,
            'status' => $resultStatus,
            'message' => $message,
        ]);
        $result->pid = (string) Str::uuid();
        $result->save();

        $task->update([
            'failed_at' => $finishedAt,
            'completed_at' => $finishedAt,
        ]);
    }
}
