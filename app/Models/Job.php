<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="Job",
 *     type="object",
 *     title="Job",
 *     description="Represents an enqueued background job (queue payload and metadata).",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="collection_id", type="integer", nullable=true, example=10, description="FK to related collection if applicable"),
 *     @OA\Property(property="queue", type="string", example="default", description="Name of the queue the job belongs to"),
 *     @OA\Property(property="payload", type="string", example="", description="Serialized payload for the queued job (JSON string)"),
 *     @OA\Property(property="attempts", type="integer", example=0, description="Number of attempts so far"),
 *     @OA\Property(property="reserved_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:34:56Z", description="Timestamp when job was reserved (processing started)"),
 *     @OA\Property(property="available_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:35:00Z", description="Timestamp when job becomes available for processing"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z", description="Timestamp when job was enqueued"),
 *     @OA\Property(
 *         property="collection",
 *         type="object",
 *         nullable=true,
 *         ref="#/components/schemas/Collection",
 *         description="Optional collection relationship, if the job is related to a collection"
 *     )
 * )
 */
class Job extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'collection_id',
        'queue',
        'payload',
        'attempts',
        'reserved_at',
        'available_at',
        'created_at'
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
