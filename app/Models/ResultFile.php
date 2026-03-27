<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\ResultFile
 *
 * @OA\Schema(
 *     schema="ResultFile",
 *     type="object",
 *     title="ResultFile",
 *     description="Represents a file produced from a result export or processing task.",
 *     required={"task_id", "path", "file_name", "status"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="pid", type="string", example="rf_abc123", description="Public identifier for the result file"),
 *     @OA\Property(property="task_id", type="integer", example=22, description="FK to the Task that created this file"),
 *     @OA\Property(property="collection_id", type="integer", nullable=true, example=10, description="FK to the Collection related to this file"),
 *     @OA\Property(property="path", type="string", example="/var/www/storage/results/res_abc123.csv", description="File system path to the file"),
 *     @OA\Property(property="file_name", type="string", example="res_abc123.csv", description="Filename"),
 *     @OA\Property(property="file_type", type="string", nullable=true, example="text/csv", description="MIME type of the file"),
 *     @OA\Property(property="file_description", type="string", nullable=true, example="CSV export of query results", description="Human readable description of the file"),
 *     @OA\Property(property="status", type="string", example="queued", description="File processing status: queued|processing|done|failed"),
 *     @OA\Property(property="rows_processed", type="integer", nullable=true, example=250, description="Number of rows processed/written to the file"),
 *     @OA\Property(property="error", type="string", nullable=true, example="CSV writer failed", description="Error message if processing failed"),
 *     @OA\Property(property="hash", type="string", nullable=true, example="e3b0c44298fc1c149afbf4c8996fb924", description="Optional file checksum/hash"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="task", ref="#/components/schemas/Task", description="Optional associated Task object"),
 *     @OA\Property(property="collection", ref="#/components/schemas/Collection", description="Optional associated Collection object")
 * )
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $collection_id
 * @property string $path
 * @property string $file_name
 * @property string|null $file_type
 * @property string|null $file_description
 * @property string $status
 * @property int|null $rows_processed
 * @property string|null $error
 * @property string|null $hash
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Task $task
 * @property-read \App\Models\Collection|null $collection
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

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function scopeQueued(Builder $q): Builder
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
        if (! is_null($rowsProcessed)) {
            $attrs['rows_processed'] = $rowsProcessed;
        }
        $this->update($attrs);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $message,
        ]);
    }
}
