<?php

namespace App\Models;

use App\Enums\TaskType;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @OA\Schema(
 *     schema="Task",
 *     type="object",
 *     title="Task",
 *     description="A background processing task that executes a saved query against a collection.",
 *     required={"pid", "query_id", "collection_id", "task_type"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="pid", type="string", example="tsk_abc123", description="Public identifier for the task"),
 *     @OA\Property(property="query_id", type="integer", example=5, description="FK to the saved query"),
 *     @OA\Property(property="collection_id", type="integer", example=10, description="FK to the collection the task targets"),
 *     @OA\Property(property="task_type", type="string", example="A", description="Type of task (enum)"),
 *     @OA\Property(property="attempts", type="integer", example=0, description="Number of attempts so far"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="attempted_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:35:00Z"),
 *     @OA\Property(property="completed_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:36:00Z"),
 *     @OA\Property(property="failed_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:36:30Z"),
 *     @OA\Property(property="leased_by", type="string", format="string", nullable=true, example="127.0.1"),
 *     @OA\Property(property="leased_until", type="string", format="date-time", nullable=true, example="2025-08-06T12:36:30Z"),
 *     @OA\Property(property="result", ref="#/components/schemas/Result", description="Optional associated result object"),
 *     @OA\Property(property="resultFiles", type="array", @OA\Items(ref="#/components/schemas/ResultFile"), description="Optional files produced by the task"),
 *     @OA\Property(property="latestRun", type="array", @OA\Items(ref="#/components/schemas/TaskRun"), description="Latest run attempt")
 * )
 *
 * @property int $id
 * @property \App\Models\Query $submittedQuery
 * @property \App\Models\Collection $collection
 */
class Task extends Model
{
    use HasFactory;
    use Search;
    use Filter;

    public $timestamps = false;

    protected $fillable = [
        'pid',
        'query_id',
        'collection_id',
        'created_at',
        'completed_at',
        'attempted_at',
        'failed_at',
        'attempts',
        'task_type',
        'leased_by',
        'leased_until',
    ];

    protected $casts = [
        'leased_until' => 'datetime',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempted_at' => 'datetime',
        'failed_at' => 'datetime',
        'task_type' => TaskType::class,
    ];

    protected static array $sortableColumns = [
        'collection.name',
    ];

    protected static function booted(): void
    {
        static::creating(function ($task) {
            $task->pid = $task->pid ?? (string) Str::uuid();
        });
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id', 'id');
    }

    public function submittedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id', 'id');
    }

    public function result(): HasOne
    {
        return $this->hasOne(Result::class);
    }

    public function resultFiles()
    {
        return $this->hasMany(ResultFile::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(TaskRun::class)->latestOfMany();
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }
}
