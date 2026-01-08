<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="UserHasRole",
 *     type="object",
 *     title="UserHasRole",
 *     description="Pivot model linking a User to a Role",
 *     required={"user_id","role_id"},
 *     @OA\Property(property="user_id", type="integer", example=2, description="FK to users"),
 *     @OA\Property(property="role_id", type="integer", example=1, description="FK to roles")
 * )
 */
class UserHasRole extends Model
{
    public $table = 'user_has_roles';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'role_id',
    ];
}
