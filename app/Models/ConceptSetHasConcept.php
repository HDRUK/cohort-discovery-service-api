<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="ConceptSetHasConcept",
 *     type="object",
 *     title="ConceptSetHasConcept",
 *     description="Pivot linking a concept to a ConceptSet",
 *     required={"concept_set_id","concept_id"},
 *     @OA\Property(property="concept_set_id", type="integer", example=1, description="FK to concept_sets"),
 *     @OA\Property(property="concept_id", type="integer", example=12345, description="FK to the concept (distribution/concept id)")
 * )
 */
class ConceptSetHasConcept extends Pivot
{
    public $table = 'concept_set_has_concept';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'concept_id',
        'concept_set_id',
    ];

    public function conceptSet(): BelongsTo
    {
        return $this->belongsTo(ConceptSet::class);
    }
}
