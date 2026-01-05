<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="TaskRun",
 *     type="object",
 *     title="TaskRun",
 *     description="Logs when tasks have been attempted.",
 *     required={"task_id", "attempt", "worker_id"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="task_id", type="integer", example=5, description="FK to the saved tasked"),
 *     @OA\Property(property="worker_id", type="string", example=10, description="Worker id returned by BUNNY via the request and/or IP address"),
 *     @OA\Property(property="attempt", type="integer", example=0, description="Number of the attempt made so far"),
 *     @OA\Property(property="claimed_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="started_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:35:00Z"),
 *     @OA\Property(property="finished_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:36:00Z"),
 *     @OA\Property(property="result_status", type="string", format="string", nullable=true, example="127.0.1"),
 * )
 *
 * @property int $id
 * @property \App\Models\Task $task
 */
class TaskRun extends Model
{
    protected $fillable = [
        'task_id',
        'attempt',
        'worker_id',
        'claimed_at',
        'started_at',
        'finished_at',
        'result_status',
        'result_count',
        'duration_ms',
        'error_class',
        'error_message',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
