<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @OA\Schema(
 *     schema="Result",
 *     type="object",
 *     title="Result",
 *     description="Result record produced from running a task (counts, metadata and status).",
 *     required={"task_id"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="pid", type="string", example="res_abc123", description="Public identifier for the result"),
 *     @OA\Property(property="task_id", type="integer", example=22, description="FK to the Task that produced this result"),
 *     @OA\Property(property="collection_id", type="integer", nullable=true, example=10, description="FK to the Collection this result is associated with (if any)"),
 *     @OA\Property(property="count", type="integer", nullable=true, example=123, description="Count of matching records"),
 *     @OA\Property(property="metadata", type="object", nullable=true, description="JSON metadata describing the result (statistics, parameters, etc.)"),
 *     @OA\Property(property="status", type="string", nullable=true, example="COMPLETED", description="Processing status"),
 *     @OA\Property(property="message", type="string", nullable=true, example="Processed successfully", description="Optional human readable message"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class Result extends Model
{
    protected $fillable = [
        'task_id',
        'count',
        'metadata',
        'status',
        'message'
    ];

    protected $casts = [
        'count' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($res) {
            $res->pid = $res->pid ?? (string) Str::uuid();
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
