<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use App\Contracts\ValidatableModel;

/**
 * @OA\Schema(
 *     schema="CollectionHost",
 *     type="object",
 *     title="CollectionHost",
 *     required={"name", "query_context_type", "user_id"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Host A"),
 *     @OA\Property(property="query_context_type", type="string", example="FHIR"),
 *     @OA\Property(property="user_id", type="integer", example=5),
 *     @OA\Property(property="client_id", type="string", example="abc123"),
 *     @OA\Property(property="client_secret", type="string", example="secretXYZ"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class CollectionHost extends Model implements ValidatableModel
{
    /** @use HasFactory<\Database\Factories\CollectionHostFactory> */
    use HasFactory;
    use Search;
    use Filter;

    public $table = 'collection_hosts';
    public $timestamps = true;

    protected $fillable = [
        'name',
        'query_context_type',
        'user_id',
        'client_id',
        'client_secret',
        'custodian_id',
    ];

    protected static array $searchableColumns = [
        'name',
        'query_context_type',
    ];

    protected static array $sortableColumns = [
        'name',
    ];

    protected static array $filterableColumns = [
        'created_at',
        'updated_at',
        'custodian_id',
    ];

    public function getValidationRules(string $context): array
    {
        return match(strtolower($context)) {
            'index' => [],
            'show' => [
                'id' => 'required|integer|exists:collection_hosts,id',
            ],
            'store' => [
                'name' => 'required|string|max:255',
                'query_context_type' => 'required|string|max:255',
                'custodian_id' => 'required|integer|exists:custodians,id',
            ],
            'update' => [
                'id' => 'required|integer|exists:collection_hosts,id',
                'name' => 'sometimes|string|max:255',
                'query_context_type' => 'sometimes|string|max:255',
            ],
            'delete' => [
                'id' => 'required|integer|exists:collection_hosts,id',
            ],
            default => [],
        };
    }

    public function collections(): HasManyThrough
    {
        return $this->hasManyThrough(
            Collection::class,
            CollectionHostHasCollection::class,
            'collection_host_id',
            'id',
            'id',
            'collection_id'
        );
    }
}
