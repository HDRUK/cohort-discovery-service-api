<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;


/**
 * App\Models\ResultFile
 *
 * @property int $id
 * @property int $task_id
 * @property int $collection_id
 * @property string $path
 * @property string $file_name
 * @property string|null $file_type
 * @property string|null $file_description
 * @property string $status        queued|processing|done|failed
 * @property int $rows_processed
 * @property string|null $error
 * @property string|null $hash
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read \App\Models\Task $task
 * @property-read \App\Models\Collection $collection
 */
class ResultFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'pid',
        'task_id',
        'collection_id',
        'path',
        'file_name',
        'file_type',
        'file_description',
        'status',
        'rows_processed',
        'error',
    ];

    protected $casts = [
        'rows_processed' => 'integer',
    ];

    public const STATUS_QUEUED     = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function collection()
    {
        return $this->belongsTo(Collection::class);
    }

    public function scopeQueued($q)
    {
        return $q->where('status', self::STATUS_QUEUED);
    }

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markDone(?int $rowsProcessed = null): void
    {
        $attrs = ['status' => self::STATUS_DONE];
        if (!is_null($rowsProcessed)) {
            $attrs['rows_processed'] = $rowsProcessed;
        }
        $this->update($attrs);
    }

    public function markFailed(string $message): void
    {
        $this->update(['status' => self::STATUS_FAILED, 'error' => $message]);
    }
}
