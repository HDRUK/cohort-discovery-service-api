<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @OA\Schema(
 *     schema="ConceptSet",
 *     type="object",
 *     title="ConceptSet",
 *     description="A user-defined set of concepts (used for query building / cohort definitions).",
 *     required={"user_id","domain","name"},
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="user_id", type="integer", example=2, description="FK to the user who owns the concept set"),
 *     @OA\Property(property="domain", type="string", example="Condition", description="OMOP domain for the concepts in this set"),
 *     @OA\Property(property="name", type="string", example="Diabetes Concepts", description="Human readable name"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Set containing diabetes related concepts"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-08-06T12:34:56Z"),
 *     @OA\Property(property="deleted_at", type="string", format="date-time", nullable=true, example="2025-08-06T12:34:56Z"),
 *     @OA\Property(
 *         property="user",
 *         type="object",
 *         description="Owner user object",
 *         ref="#/components/schemas/User"
 *     ),
 *     @OA\Property(
 *         property="concepts",
 *         type="array",
 *         description="List of concepts within this set",
 *         @OA\Items(
 *             type="object",
 *             @OA\Property(property="concept_id", type="integer", example=12345),
 *             @OA\Property(property="concept_name", type="string", example="Type 2 diabetes mellitus"),
 *             @OA\Property(property="domain", type="string", example="Condition")
 *         )
 *     )
 * )
 */
class ConceptSet extends Model
{
    /** @use HasFactory<\Database\Factories\ConceptSetFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'domain',
        'name',
        'description',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(
            Distribution::class,
            'concept_set_has_concept',
            'concept_set_id',
            'concept_id',
            'id',
            'concept_id'
        )
            ->using(ConceptSetHasConcept::class);
    }


    public function scopeForDomain($q, string $domain)
    {
        return $q->where('domain', $domain);
    }
}
