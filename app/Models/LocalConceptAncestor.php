<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="LocalConceptAncestor",
 *     type="object",
 *     title="LocalConceptAncestor",
 *     description="Represents an ancestor/descendant relationship between OMOP concepts, used to map hierarchy within local concept dataset.",
 *     required={"parent_concept_id","child_concept_id"},
 *     @OA\Property(property="parent_concept_id", type="integer", example=12345, description="OMOP concept_id of the ancestor/parent concept"),
 *     @OA\Property(property="child_concept_id", type="integer", example=67890, description="OMOP concept_id of the descendant/child concept")
 * )
 */
class LocalConceptAncestor extends Model
{
    protected $table = 'concept_ancestors';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'parent_concept_id',
        'child_concept_id',
    ];
}
