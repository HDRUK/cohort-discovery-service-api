<?php

namespace App\Models;

use App\Contracts\ValidatableModel;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="CollectionHost",
 *     type="object",
 *     title="CollectionHost",
 *     required={"name", "query_context_type", "user_id"},
 *
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
    use Filter;

    /** @use HasFactory<\Database\Factories\CollectionHostFactory> */
    use HasFactory;
    use Search;

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
        return match (strtolower($context)) {
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
            'destroy' => [
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

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Custodian::class, 'custodian_id');
    }
}
