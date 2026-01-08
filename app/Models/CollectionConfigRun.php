<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="CollectionConfigRun",
 *     type="object",
 *     title="CollectionConfigRun",
 *     description="Represents an execution run of a CollectionConfig - records the query and task run details",
 *     required={"collection_config_id","ran_at","successful"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="collection_config_id", type="integer", example=10, description="FK to collection_config"),
 *     @OA\Property(property="query_id", type="integer", nullable=true, example=5, description="FK to the saved query executed (if any)"),
 *     @OA\Property(property="task_id", type="integer", nullable=true, example=22, description="Associated task id for the run"),
 *     @OA\Property(property="ran_at", type="string", format="date-time", example="2025-08-06T12:34:56Z", description="Timestamp when the run occurred"),
 *     @OA\Property(property="successful", type="boolean", example=true, description="Whether the run completed successfully"),
 *     @OA\Property(property="errors", type="string", nullable=true, example="Database timeout", description="Error details if the run failed"),
 * )
 */
class CollectionConfigRun extends Model
{
    public $table = 'collection_config_runs';

    public $timestamps = false;

    protected $fillable = [
        'collection_config_id',
        'query_id',
        'task_id',
        'ran_at',
        'successful',
        'errors',
    ];

    protected $casts = [
        'successful' => 'boolean',
    ];
}
