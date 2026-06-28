<?php

namespace App\Models;

use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="DistributionConcept",
 *     type="object",
 *     title="DistributionConcept",
 *     description="A concept-level aggregated representation of distribution data across collections. For each concept, the latest distribution per collection is used, then counts are summed across collections.",
 *     @OA\Property(property="concept_id", type="integer", nullable=true, example=12345, description="OMOP concept identifier"),
 *     @OA\Property(property="concept_name", type="string", nullable=true, example="Type 2 diabetes mellitus"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Type 2 diabetes mellitus", description="Description used for search/display"),
 *     @OA\Property(property="domain_id", type="string", nullable=true, example="Condition"),
 *     @OA\Property(property="vocabulary_id", type="string", nullable=true, example="SNOMED"),
 *     @OA\Property(property="concept_class", type="string", nullable=true, example="Clinical Finding"),
 *     @OA\Property(property="standard_concept", type="string", nullable=true, example="S", description="Standard concept flag"),
 *     @OA\Property(property="concept_code", type="string", nullable=true, example="44054006"),
 *     @OA\Property(property="count", type="integer", nullable=true, example=100, description="Sum of the latest distribution counts across collections for this concept"),
 *     @OA\Property(property="ncollections", type="integer", nullable=true, example=12, description="Number of collections contributing to this concept")
 * )
 */
class DistributionConcept extends Model
{
    use Search;

    protected $table = 'distribution_concepts';

    protected $primaryKey = 'concept_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'int';

    protected static $searchableColumns = [
        'concept_id',
        'concept_name',
        'all_synthetic'
    ];

    protected static $sortableColumns = [
        'concept_id',
        'concept_name',
        'all_synthetic',
    ];


    protected $fillable = [
        'concept_id',
        'concept_name',
        'description',
        'domain_id',
        'vocabulary_id',
        'concept_class',
        'standard_concept',
        'concept_code',
        'count',
        'ncollections',
        'all_synthetic'
    ];
}
