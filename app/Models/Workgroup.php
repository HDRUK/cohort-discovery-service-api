<?php

namespace App\Models;

use App\Contracts\ValidatableModel;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @OA\Schema(
 *     schema="Workgroup",
 *     type="object",
 *     title="Workgroup",
 *     required={"name"},
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Research Team"),
 *     @OA\Property(property="active", type="boolean", example=true),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class Workgroup extends Model implements ValidatableModel
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

    public function getValidationRules(string $context): array
    {
        return match (strtolower($context)) {
            'index' => [],
            'show' => [
                'id' => 'required|integer|exists:workgroups,id',
            ],
            'store' => [
                'name' => 'required|string|min:3|max:255',
                'active' => 'required|boolean',
            ],
            'update' => [
                'id' => 'required|integer|exists:workgroups,id',
                'name' => 'sometimes|string|min:3|max:255',
                'active' => 'sometimes|boolean',
            ],
            'delete' => [
                'id' => 'required|integer|exists:workgroups,id',
            ],
            default => [],
        };
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(
            Collection::class,
            'workgroup_has_collection',
            'workgroup_id',
            'collection_id',
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_has_workgroups',
            'workgroup_id',
            'user_id'
        );
    }
}
