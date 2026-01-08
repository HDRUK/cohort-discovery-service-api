<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="Role",
 *     type="object",
 *     title="Role",
 *     description="Model for Role",
 *     @OA\Property(property="name", type="string", example='admin', description="name of role")
 * )
 */
class Role extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'name',
    ];
}
