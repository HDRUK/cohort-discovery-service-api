<?php

namespace App\Models;

use App\Contracts\ValidatableModel;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @OA\Schema(
 *     schema="CustodianNetwork",
 *     type="object",
 *     title="CustodianNetwork",
 *     description="A network grouping of custodians",
 *     required={"name"},
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="North West Network"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 */
class CustodianNetwork extends Model implements ValidatableModel
{
    use Filter;

    /** @use HasFactory<\Database\Factories\CustodianNetworkFactory> */
    use HasFactory;
    use Search;

    public $table = 'custodian_networks';

    public $timestamps = true;

    protected $fillable = [
        'pid',
        'name',
    ];

    protected static array $searchableColumns = [
        'pid',
        'name',
    ];

    protected static array $sortableColumns = [
        'name',
    ];

    protected static array $filterableColumns = [
        'created_at',
        'updated_at',
        'name',
    ];

    public function getValidationRules(string $context): array
    {
        return match (strtolower($context)) {
            'index' => [],
            'show' => [
                'id' => 'required|integer|exists:custodian_networks,id',
            ],
            'store' => [
                'name' => 'required|string|max:255',
            ],
            'update' => [
                'id' => 'required|integer|exists:custodian_networks,id',
                'name' => 'sometimes|string|max:255',
            ],
            'destroy' => [
                'id' => 'required|integer|exists:custodian_networks,id',
            ],
            default => [],
        };
    }

    public function custodians(): HasManyThrough
    {
        return $this->hasManyThrough(
            Custodian::class,
            CustodianNetworkHasCustodian::class,
            'network_id',
            'id',
            'id',
            'custodian_id'
        );
    }
}
