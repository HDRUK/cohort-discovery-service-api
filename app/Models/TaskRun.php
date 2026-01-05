<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
