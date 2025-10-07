<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Hdruk\LaravelSearchAndFilter\Traits\Search;

/**
 * @OA\Schema(
 *     schema="Workgroup",
 *     type="object",
 *     title="Workgroup",
 *     required={"name"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Research Team"),
 *     @OA\Property(property="active", type="boolean", example=true),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class Workgroup extends Model
{
    use Search;

    public $timestamps = true;

    protected $fillable = [
        'name',
        'active',
    ];

    protected static array $searchableColumns = [
        'name',
    ];

    protected static array $sortableColumns = [
        'id',
        'name',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_has_workgroups', 'workgroup_id', 'user_id');
    }
}
