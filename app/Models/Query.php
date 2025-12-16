<?php

namespace App\Models;

use App\Enums\TaskType;
use App\Rules\IdOrUuid;
use App\Traits\Downloadable;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use App\Enums\QueryType;
use Carbon\Carbon;

/**
 * @OA\Schema(
 *     schema="Query",
 *     type="object",
 *     title="Query",
 *     required={"pid","name","definition"},
 *
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="pid", type="string", example="qry_abc123"),
 *     @OA\Property(property="name", type="string", example="Cardiology cohort query"),
 *     @OA\Property(property="user_id", type="integer", nullable=true, example=2),
 *     @OA\Property(
 *         property="definition",
 *         type="array",
 *         description="Structured query definition (array of rule objects)",
 *
 *         @OA\Items(type="object")
 *     ),
 *
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 *
 * @property int $id
 * @property string $name
 * @property array $definition
 */
class Query extends Model
{
    use Downloadable;
    use Filter;
    use HasFactory;
    use Search;

    public $timestamps = false;

    protected $fillable = [
        'pid',
        'name',
        'user_id',
        'definition',
        'created_at',
        'query_type',
    ];

    protected $casts = [
        'definition' => 'array',
        'created_at' => 'datetime',
    ];

    protected static array $searchableColumns = [
        'pid',
        'name',
        'definition',
    ];

    protected static array $sortableColumns = [
        'name',
        'created_at',
    ];

    public static function downloadableFields(): array
    {
        return [
            'id',
            'pid',
            'name',
            'definition',
            'created_at',
        ];
    }

    public function getValidationRules(string $context): array
    {
        return match (strtolower($context)) {
            'index' => [],
            'show' => [
                'key' => [
                    'required',
                    new IdOrUuid(),
                ],
            ],
            'store' => [
                'name' => 'nullable|string|min:3|max:255',
                'definition' => 'required|array',
                'collection_filter' => 'nullable|array',
                'task_type' => [
                    'required',
                    new Enum(TaskType::class),
                ],
            ],
            'update' => [
                'name' => 'sometimes|string|min:3|max:255',
                'definition' => 'sometimes|array',
            ],
            'delete' => [
                'key' => [
                    'required',
                    new IdOrUuid(),
                ],
            ],
            default => [],
        };
    }

    public static function createDistributionQuery(Collection $collection, QueryType $type): self
    {
        $code = $type->value;
        $name = sprintf('test-%s-%s-%s', $collection->name, $code, Carbon::now()->format('Ymd_His'));
        $collectionId = $collection->id;

        $query = self::create([
            'pid' => (string) Str::uuid(),
            'name' => $name,
            'definition' => [
                'code' => $type->value,
            ],
            'query_type' => $type->value
        ]);

        $query->tasks()->create([
                'pid' => (string) Str::uuid(),
                'collection_id' => $collectionId,
                'task_type' => TaskType::B
        ]);

        $query->refresh()->load('tasks');
        return  $query;

    }


    protected static function booted(): void
    {
        static::creating(function ($query) {
            $query->pid = $query->pid ?? (string) Str::uuid();
        });
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'query_id');
    }
}
