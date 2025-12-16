<?php

namespace App\Models;

use Illuminate\Validation\Rules\Enum;
use App\Enums\QueryType;
use App\Contracts\ValidatableModel;
use App\Models\Omop\Concept;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Config;

/**
 * @OA\Schema(
 *     schema="Distribution",
 *     type="object",
 *     title="Distribution",
 *     description="A distribution (result) produced from running a query on a collection (e.g., a count or measurement summary).",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="collection_id", type="integer", example=10, description="FK to collections"),
 *     @OA\Property(property="task_id", type="integer", nullable=true, example=22, description="FK to task that produced this distribution"),
 *     @OA\Property(property="category", type="string", example="DEMOGRAPHICS", description="Category for the distribution (e.g. DEMOGRAPHICS, MEASUREMENT)"),
 *     @OA\Property(property="name", type="string", example="SEX", description="Distribution name"),
 *     @OA\Property(property="description", type="string", example="Sex distribution for cohort", nullable=true),
 *     @OA\Property(property="concept_id", type="integer", nullable=true, example=12345, description="OMOP concept identifier if applicable"),
 *     @OA\Property(property="count", type="integer", example=100, description="Count of matching records"),
 *     @OA\Property(property="q1", type="number", format="float", example=1.23, nullable=true),
 *     @OA\Property(property="q3", type="number", format="float", example=4.56, nullable=true),
 *     @OA\Property(property="min", type="number", format="float", example=0.5, nullable=true),
 *     @OA\Property(property="max", type="number", format="float", example=99.9, nullable=true),
 *     @OA\Property(property="mean", type="number", format="float", example=12.34, nullable=true),
 *     @OA\Property(property="median", type="number", format="float", example=10.5, nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z")
 * )
 *
 * @property int $id
 * @property int $collection_id
 * @property int $task_id
 * @property string $name
 * @property string $description
 * @property number $count
 */
class Distribution extends Model implements ValidatableModel
{
    use Search;

    protected $hidden = ['pivot'];

    public function getConnectionName()
    {
        return Config::get('database.default');
    }

    protected $fillable = [
        'collection_id',
        'task_id',
        'category',
        'name',
        'description',
        'concept_id',
        'count',
        'q1',
        'q3',
        'min',
        'max',
        'mean',
        'median',
    ];

    protected static $searchableColumns = [
        'concept_id',
        'name',
        'description',
    ];

    public function getValidationRules(string $context): array
    {
        return match (strtolower($context)) {
            'store' => [
                'query_type'    => ['required', new Enum(QueryType::class)],
            ],
            default => [],
        };
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function concept()
    {
        return $this->belongsTo(Concept::class, 'concept_id', 'concept_id');
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            LocalConceptAncestor::class,
            'parent_concept_id',
            'child_concept_id',
            'concept_id',
            'concept_id'
        );
    }
}
