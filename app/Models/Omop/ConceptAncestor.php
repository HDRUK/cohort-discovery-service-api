<?php

namespace App\Models\Omop;

use Illuminate\Database\Eloquent\Model;


class ConceptAncestor extends Model
{
    protected $connection = 'omop';
    protected $table = 'concept_ancestor';
    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'ancestor_concept_id',
        'descendant_concept_id',
        'min_levels_of_separation',
        'max_levels_of_separation',
    ];

    /**
     * The ancestor concept.
     */
    public function ancestor()
    {
        return $this->belongsTo(Concept::class, 'ancestor_concept_id');
    }

    /**
     * The descendant concept.
     */
    public function descendant()
    {
        return $this->belongsTo(Concept::class, 'descendant_concept_id');
    }
}
