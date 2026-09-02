<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="CollectionPingBucket",
 *     type="object",
 *     title="CollectionPingBucket",
 *     description="Count of collection host polls (pings) within a single minute, per task type",
 *     required={"collection_id", "task_type", "bucket_minute", "n"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="collection_id", type="integer", example=10),
 *     @OA\Property(property="task_type", type="string", enum={"a", "b"}, example="a", description="Task type polled for"),
 *     @OA\Property(property="bucket_minute", type="string", format="date-time", example="2026-09-01T09:59:00Z", description="Start of the minute this row counts (UTC)"),
 *     @OA\Property(property="n", type="integer", example=12, description="Number of pings received in the minute"),
 *     @OA\Property(property="first_ping_at", type="string", format="date-time", example="2026-09-01T09:59:02Z"),
 *     @OA\Property(property="last_ping_at", type="string", format="date-time", example="2026-09-01T09:59:57Z")
 * )
 */
class CollectionPingBucket extends Model
{
    public $table = 'collection_ping_buckets';

    // first_ping_at / last_ping_at already carry the time information.
    public $timestamps = false;

    protected $fillable = [
        'collection_id',
        'task_type',
        'bucket_minute',
        'n',
        'first_ping_at',
        'last_ping_at',
    ];

    protected $casts = [
        'bucket_minute' => 'datetime',
        'first_ping_at' => 'datetime',
        'last_ping_at' => 'datetime',
        'n' => 'integer',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
