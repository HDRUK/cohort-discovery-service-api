<?php

namespace App\Models;

use App\Contracts\ValidatableModel;
use App\Enums\QueryType;
use App\Enums\TaskType;
use App\Services\QueryContext\QueryContextType;
use Carbon\Carbon;
use Hdruk\LaravelModelStates\Contracts\HasStateTransitions;
use Hdruk\LaravelModelStates\Models\ModelState;
use Hdruk\LaravelModelStates\Models\State;
use Hdruk\LaravelModelStates\Traits\HasState;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Schema(
 *     schema="Collection",
 *     type="object",
 *     title="Collection",
 *     description="A data collection (cohort) containing tasks, distributions and host relationship metadata.",
 *     required={"name","pid","type"},
 *
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
 *
 *         @OA\Items(ref="#/components/schemas/Task")
 *     ),
 *
 *     @OA\Property(
 *         property="host",
 *         type="array",
 *         description="Associated collection host(s)",
 *
 *         @OA\Items(ref="#/components/schemas/CollectionHost")
 *     ),
 *
 *     @OA\Property(
 *         property="workgroups",
 *         type="array",
 *         description="Associated workgroups",
 *
 *         @OA\Items(ref="#/components/schemas/Workgroup")
 *     )
 * )
 *
 * @property int $id
 * @property string $name
 * @property string $pid
 * @property QueryContextType $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ModelState|null $modelState
 * @property-read State|null $state
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $tasks
 * @property-read \App\Models\ResultFile|null $latestMetadataResultFile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Distribution> $demographics
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Distribution> $concepts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Distribution> $conceptCountsByCategory
 */
class Collection extends Model implements HasStateTransitions, ValidatableModel
{
    use Filter;
    use HasFactory;
    use HasState;
    use Search;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';

    public $table = 'collections';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'description',
        'url',
        'pid',
        'type',
        'custodian_id',
        'status',
        'updated_at',
        'workgroup_ids',
        'is_synthetic',
    ];

    protected $casts = [
        'type' => QueryContextType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'last_active' => 'datetime',
        'is_synthetic' => 'boolean',
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

    protected static array $transitions = [
        self::STATUS_DRAFT => [
            self::STATUS_PENDING,
            self::STATUS_ACTIVE,
            self::STATUS_REJECTED,
            self::STATUS_SUSPENDED,
        ],
        self::STATUS_PENDING => [
            self::STATUS_DRAFT,
            self::STATUS_ACTIVE,
            self::STATUS_REJECTED,
            self::STATUS_SUSPENDED,
        ],
        self::STATUS_ACTIVE => [
            self::STATUS_DRAFT,
            self::STATUS_SUSPENDED,
        ],
        self::STATUS_REJECTED => [
            self::STATUS_DRAFT,
            self::STATUS_ACTIVE,
        ],
        self::STATUS_SUSPENDED => [
            self::STATUS_DRAFT,
            self::STATUS_ACTIVE,
        ],
    ];

    public function getValidationRules(string $context): array
    {
        return match (strtolower($context)) {
            'index' => [],
            'show' => [
                'id' => 'required|integer|exists:collections,id',
            ],
            'store' => [
                'name' => 'required|string|min:3|max:255',
                'description' => 'sometimes|string|min:0|max:65535',
                'url' => 'required|string|max:255',
                'pid' => 'required|string',
                'type' => 'required|string',
                'custodian_id' => 'required|integer|exists:custodians,id',
                'status' => 'required|boolean',
                'is_synthetic' => 'sometimes|boolean',
            ],
            'update' => [
                'id' => 'required|integer|exists:collections,id',
                'name' => 'sometimes|string|min:3|max:255',
                'description' => 'sometimes|string|min:0|max:65535',
                'url' => 'sometimes|string|max:255',
                'pid' => 'sometimes|string',
                'type' => 'sometimes|string',
                'custodian_id' => 'sometimes|integer|exists:custodians,id',
                'status' => 'sometimes|boolean',
                'state' => 'sometimes|string',
                'host_id' => 'sometimes|integer|exists:collection_hosts,id',
                'is_synthetic' => 'sometimes|boolean',
            ],
            'destroy' => [
                'id' => 'required|integer|exists:collections,id',
            ],
            'addtoworkgroup' => [
                'workgroup_id' => 'required|integer|exists:workgroups,id',
            ],
            'removefromworkgroup' => [],
            default => [],
        };
    }

    public static function getStateTransitions(): array
    {
        return static::$transitions;
    }

    public function modelState(): MorphOne
    {
        return $this->morphOne(ModelState::class, 'stateable');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Custodian::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function latestDemographicTask(): HasOne
    {
        return $this->hasOne(Task::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            function (Builder $q) {
                $q->where('task_type', TaskType::B)
                    ->whereRelation('submittedQuery', 'query_type', QueryType::DEMOGRAPHICS->value);
            }
        );
    }

    public function latestConceptTask(): HasOne
    {
        return $this->hasOne(Task::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            function (Builder $q) {
                $q->where('task_type', TaskType::B)
                    ->whereRelation('submittedQuery', 'query_type', QueryType::GENERIC->value);
            }
        );
    }

    public function latestSuccessfulDemographicTask(): HasOne
    {
        return $this->hasOne(Task::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            function (Builder $q) {
                $q->where('task_type', TaskType::B)
                    ->whereRelation('submittedQuery', 'query_type', QueryType::DEMOGRAPHICS->value)
                    ->whereHas('result');
            }
        );
    }

    public function latestSuccessfulConceptTask(): HasOne
    {
        return $this->hasOne(Task::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            function (Builder $q) {
                $q->where('task_type', TaskType::B)
                    ->whereRelation('submittedQuery', 'query_type', QueryType::GENERIC->value)
                    ->whereHas('result');
            }
        );
    }

    public function latestSuccessfulDemographicResultFile(): HasOne
    {
        return $this->hasOne(ResultFile::class)->ofMany(
            ['updated_at' => 'max', 'id' => 'max'],
            function (Builder $q) {
                $q->where('file_name', 'demographics.distribution')
                    ->where('status', 'done');
            }
        );
    }

    public function latestSuccessfulConceptResultFile(): HasOne
    {
        return $this->hasOne(ResultFile::class)->ofMany(
            ['updated_at' => 'max', 'id' => 'max'],
            function (Builder $q) {
                $q->where('file_name', 'code.distribution')
                    ->where('status', 'done');
            }
        );
    }

    public function resultFiles(): HasMany
    {
        return $this->hasMany(ResultFile::class);
    }

    public function demographics(): HasMany
    {
        $latest = Distribution::query()
            ->selectRaw('MAX(id) as id')
            ->where('category', 'DEMOGRAPHICS')
            ->groupBy('collection_id', 'concept_id');

        return $this->hasMany(Distribution::class, 'collection_id')
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('distributions.id', '=', 'latest.id');
            })
            ->select('distributions.*');
    }

    public function concepts(): HasMany
    {
        $latest = DB::table('distributions')
            ->selectRaw('collection_id, concept_id, MAX(id) as id')
            ->where('concept_id', '>', 0)
            ->groupBy('collection_id', 'concept_id');

        return $this->hasMany(Distribution::class, 'collection_id')
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('distributions.id', '=', 'latest.id');
            })
            ->select('distributions.*');
    }

    public function conceptCountsByCategory(): HasMany
    {
        $latest = DB::table('distributions')
            ->selectRaw('collection_id, category, concept_id, MAX(id) as id')
            ->where('concept_id', '>', 0)
            ->groupBy('collection_id', 'category', 'concept_id');

        return $this->hasMany(Distribution::class, 'collection_id')
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('distributions.id', '=', 'latest.id');
            })
            ->selectRaw('distributions.collection_id, distributions.category, COUNT(*) as nconcepts')
            ->groupBy('distributions.collection_id', 'distributions.category');
    }

    public function latestDemographic(): HasOne
    {
        return $this->hasOne(Distribution::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            function (Builder $q) {
                $q->where('category', 'DEMOGRAPHICS')
                    ->where('name', 'SEX');
            }
        );
    }

    public function latestConcept(): HasOne
    {
        return $this->hasOne(Distribution::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            function (Builder $q) {
                $q->where('concept_id', '>', 0);
            }
        );
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

    public function workgroups(): BelongsToMany
    {
        return $this->belongsToMany(
            Workgroup::class,
            'workgroup_has_collection',
            'collection_id',
            'workgroup_id',
        );
    }

    public function metadata(): HasMany
    {
        return $this->hasMany(CollectionMetadata::class);
    }

    public function latestMetadata(): HasOne
    {
        return $this->hasOne(CollectionMetadata::class)->latestOfMany();
    }

    public function latestMetadataResultFile(): HasOne
    {
        return $this->hasOne(ResultFile::class, 'collection_id')
            ->ofMany(
                [
                    'updated_at' => 'max',
                    'id' => 'max',
                ],
                fn ($query) => $query->where('file_name', 'like', '%metadata.bcos')
            );
    }

    public static function logActivity(Collection $c, TaskType $type): void
    {
        if (strtolower(config('system.collection_activity_log_type')) === 'log') {
            CollectionActivityLog::create([
                'collection_id' => $c->id,
                'task_type' => $type->value,
            ]);
        } elseif (strtolower(config('system.collection_activity_log_type')) === 'record') {
            Collection::where('id', $c->id)->update([
                'last_active' => Carbon::now(),
            ]);
        }
        //change state if -type BUNNY has come online
        if ($type === TaskType::A && $c->isInState(Collection::STATUS_SUSPENDED)) {
            $c->setState(Collection::STATUS_ACTIVE);
        }
    }

    public function scopeWithTaskCounts(Builder $query): Builder
    {
        return $query->withCount([
            'tasks as n_a_tasks' => fn ($q) =>
                $q->whereNotNull('completed_at')->where('task_type', TaskType::A),

            'tasks as n_b_tasks' => fn ($q) =>
                $q->whereNotNull('completed_at')->where('task_type', TaskType::B),
        ]);
    }
}
