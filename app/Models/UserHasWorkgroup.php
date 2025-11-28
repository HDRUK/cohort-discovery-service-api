<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @OA\Schema(
 *     schema="UserHasWorkgroup",
 *     type="object",
 *     title="UserHasWorkgroup",
 *     description="Pivot linking a User to a Workgroup",
 *     required={"user_id","workgroup_id"},
 *     @OA\Property(property="user_id", type="integer", example=2, description="FK to users"),
 *     @OA\Property(property="workgroup_id", type="integer", example=1, description="FK to workgroups"),
 *     @OA\Property(property="user", ref="#/components/schemas/User", nullable=true, description="Optional related User object"),
 *     @OA\Property(property="workgroup", ref="#/components/schemas/Workgroup", nullable=true, description="Optional related Workgroup object")
 * )
 */
class UserHasWorkgroup extends Pivot
{
    protected $table = 'user_has_workgroups';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'workgroup_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workgroup(): BelongsTo
    {
        return $this->belongsTo(Workgroup::class);
    }
}
