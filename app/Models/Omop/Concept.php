<?php

namespace App\Models\Omop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $concept_id 
 * @property int $domain_id
 * @property int $vocabulary_id
 * @property \App\Models\Omop\Concept $ancestors
 * @property \App\Models\Omop\Concept $descendants
 */
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

    public function ancestors(): BelongsToMany
    {
        return $this->belongsToMany(
            Concept::class,
            'concept_ancestor',
            'descendant_concept_id',
            'ancestor_concept_id'
        )->withPivot('min_levels_of_separation', 'max_levels_of_separation');
    }

    public function descendants(): BelongsToMany
    {
        return $this->belongsToMany(
            Concept::class,
            'concept_ancestor',
            'ancestor_concept_id',
            'descendant_concept_id'
        )->withPivot('min_levels_of_separation', 'max_levels_of_separation');
    }
}
