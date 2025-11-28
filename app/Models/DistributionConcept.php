<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="DistributionConcept",
 *     type="object",
 *     title="DistributionConcept",
 *     description="A denormalised representation of a concept within a distribution; used for exporting and reporting distribution concepts.",
 *     @OA\Property(property="distribution_id", type="integer", nullable=true, example=1, description="FK to the distribution"),
 *     @OA\Property(property="collection_id", type="integer", nullable=true, example=10, description="FK to the collection"),
 *     @OA\Property(property="task_id", type="integer", nullable=true, example=22, description="FK to the task"),
 *     @OA\Property(property="distribution_name", type="string", nullable=true, example="SEX", description="Name of the distribution"),
 *     @OA\Property(property="category", type="string", nullable=true, example="DEMOGRAPHICS", description="Category of the distribution"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Sex distribution for cohort"),
 *     @OA\Property(property="concept_id", type="integer", nullable=true, example=12345, description="OMOP concept identifier"),
 *     @OA\Property(property="concept_name", type="string", nullable=true, example="Type 2 diabetes mellitus"),
 *     @OA\Property(property="domain_id", type="string", nullable=true, example="Condition"),
 *     @OA\Property(property="vocabulary_id", type="string", nullable=true, example="SNOMED"),
 *     @OA\Property(property="concept_class_id", type="string", nullable=true, example="Clinical Finding"),
 *     @OA\Property(property="standard_concept", type="string", nullable=true, example="S", description="Standard concept flag"),
 *     @OA\Property(property="concept_code", type="string", nullable=true, example="44054006"),
 *     @OA\Property(property="count", type="integer", nullable=true, example=100, description="Count of matching records"),
 *     @OA\Property(property="q1", type="number", format="float", nullable=true, example=1.23),
 *     @OA\Property(property="q3", type="number", format="float", nullable=true, example=4.56),
 *     @OA\Property(property="min", type="number", format="float", nullable=true, example=0.5),
 *     @OA\Property(property="max", type="number", format="float", nullable=true, example=99.9),
 *     @OA\Property(property="mean", type="number", format="float", nullable=true, example=12.34),
 *     @OA\Property(property="median", type="number", format="float", nullable=true, example=10.5),
 *     @OA\Property(property="created_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:34:56Z")
 * )
 */
class DistributionConcept extends Model
{
    protected $table = 'distribution_concepts';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'distribution_id',
        'collection_id',
        'task_id',
        'distribution_name',
        'category',
        'description',
        'concept_id',
        'concept_name',
        'domain_id',
        'vocabulary_id',
        'concept_class_id',
        'standard_concept',
        'concept_code',
        'count',
        'q1',
        'q3',
        'min',
        'max',
        'mean',
        'median',
        'created_at',
        'updated_at',
    ];
}
