<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="CollectionActivityLog",
 *     type="object",
 *     title="CollectionActivityLog",
 *     description="Activity log entry for collection-level events or task runs",
 *     required={"collection_id", "task_type"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="collection_id", type="integer", example=10),
 *     @OA\Property(property="task_type", type="string", example="A", description="Task type code"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class CollectionActivityLog extends Model
{
    public $table = 'collection_activity_logs';
    public $timestamps = true;


    protected $fillable = [
        'created_at',
        'updated_at',
        'collection_id',
        'task_type',
    ];
}
