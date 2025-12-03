<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @OA\Schema(
 *     schema="WorkgroupHasCollection",
 *     type="object",
 *     title="WorkgroupHasCollection",
 *     description="Pivot linking a workgroup to a collection.",
 *     required={"workgroup_id","collection_id"},
 *
 *     @OA\Property(
 *         property="workgroup_id",
 *         type="integer",
 *         example=1,
 *         description="ID of the workgroup"
 *     ),
 *     @OA\Property(
 *         property="collection_id",
 *         type="integer",
 *         example=1,
 *         description="ID of the collection"
 *     )
 * )
 *
 * @property int $workgroup_id
 * @property int $collection_id
 */
class WorkgroupHasCollection extends Pivot
{
    protected $table = 'workgroup_has_collection';
    public $timestamps = false;

    protected $fillable = [
        'workgroup_id',
        'collection_id',
    ];
}
