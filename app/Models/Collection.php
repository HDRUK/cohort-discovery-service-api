<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use App\Services\QueryContext\QueryContextType;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use App\Contracts\ValidatableModel;

/**
 * @OA\Schema(
 *     schema="Collection",
 *     type="object",
 *     title="Collection",
 *     description="A data collection (cohort) containing tasks, distributions and host relationship metadata.",
 *     required={"name","pid","type"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Cardiology Cohort"),
 *     @OA\Property(property="pid", type="string", example="col_abc123"),
 *     @OA\Property(property="url", type="string", format="uri", example="https://example.org/collections/col_abc123"),
 *     @OA\Property(property="type", type="string", description="Query context type (enum)", example="FHIR"),
 *     @OA\Property(property="custodian_id", type="integer", nullable=true, example=2),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(
 *         property="tasks",
 *         type="array",
 *         description="Tasks associated with this collection",
 *         @OA\Items(ref="#/components/schemas/Task")
 *     ),
 *     @OA\Property(
 *         property="host",
 *         type="array",
 *         description="Associated collection host(s)",
 *         @OA\Items(ref="#/components/schemas/CollectionHost")
 *     )
 * )
 *
 * @property int $id
 * @property string $name
 * @property string $pid
 * @property QueryContextType $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Task[] $tasks
 */
class Collection extends Model implements ValidatableModel
{
    use HasFactory;
    use Search;
    use Filter;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public $table = 'collections';

    protected $fillable = [
        'name',
        'url',
        'pid',
        'type',
        'custodian_id',
        'status',
    ];

    protected $casts = [
        'type' => QueryContextType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static array $searchableColumns = [
        'name',
        'url',
        'pid',
    ];

    protected static array $sortableColumns = [
        'name',
    ];

    protected static array $filterableColumns = [
        'created_at',
        'updated_at',
        'status',
        'type',
    ];

    protected static array $groupableColumns = [
        'custodian',
    ];

    public function getValidationRules(string $context): array
    {
        return match(strtolower($context)) {
            'index' => [],
            'show' => [
                'id' => 'required|integer|exists:collections,id',
            ],
            'store' => [
                'name' => 'required|string|min:3|max:255',
                'url' => 'required|string|max:255',
                'pid' => 'required|string',
                'type' => 'required|string',
                'custodian_id' => 'required|integer|exists:custodians,id',
                'status' => 'required|boolean',
            ],
            'update' => [
                'id' => 'required|integer|exists:collections,id',
                'name' => 'sometimes|string|min:3|max:255',
                'url' => 'sometimes|string|max:255',
                'pid' => 'sometimes|string',
                'type' => 'sometimes|string',
                'custodian_id' => 'sometimes|integer|exists:custodians,id',
                'status' => 'sometimes|boolean',
            ],
            'destroy' => [
                'id' => 'required|integer|exists:collections,id',
            ],
            default => [],
        };
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Custodian::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function resultFiles()
    {
        return $this->hasMany(ResultFile::class);
    }

    public function demographics(): HasMany
    {
        $sub = Distribution::select(DB::raw('MAX(id) as id'))
            ->where('category', 'DEMOGRAPHICS')
            ->groupBy('name', 'collection_id');

        return $this->hasMany(Distribution::class)
            ->whereIn('id', $sub);
    }

    public function codes(): HasMany
    {
        $sub = Distribution::select(DB::raw('MAX(id) as id'))
            ->where('category', '!=', 'DEMOGRAPHICS')
            ->groupBy('name', 'collection_id');

        return $this->hasMany(Distribution::class)
            ->whereIn('id', $sub);
    }


    public function size(): HasOne
    {
        return $this->hasOne(Distribution::class)
            ->where([
                "category" => "DEMOGRAPHICS",
                "name" => "SEX"
            ])
            ->latest('created_at');
    }

    public function host(): BelongsToMany
    {
        return $this->belongsToMany(
            CollectionHost::class,
            'collection_host_has_collections',
            'collection_id',
            'collection_host_id'
        )->limit(1);
    }

    public function config(): HasOne
    {
        return $this->hasOne(CollectionConfig::class);
    }
}
