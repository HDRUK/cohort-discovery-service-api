<?php

namespace App\Models\Omop;

use Illuminate\Database\Eloquent\Model;

class Concept extends Model
{
    protected $connection = 'omop';
    protected $table = 'concept';
    protected $primaryKey = 'concept_id';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'concept_id',
        'concept_name',
        'domain_id',
        'vocabulary_id',
        'concept_class_id',
        'standard_concept',
        'invalid_reason',
    ];

    /** 
     * Ancestors of this concept (concepts above it in hierarchy).
     */
    public function ancestors()
    {
        return $this->belongsToMany(
            Concept::class,
            'concept_ancestor',
            'descendant_concept_id',
            'ancestor_concept_id'
        )->withPivot('min_levels_of_separation', 'max_levels_of_separation');
    }

    /**
     * Descendants of this concept (concepts below it in hierarchy).
     */
    public function descendants()
    {
        return $this->belongsToMany(
            Concept::class,
            'concept_ancestor',
            'ancestor_concept_id',
            'descendant_concept_id'
        )->withPivot('min_levels_of_separation', 'max_levels_of_separation');
    }
}
