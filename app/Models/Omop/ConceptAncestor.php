<?php

namespace App\Models\Omop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $ancestor_concept_id
 * @property int $descendant_concept_id
 * @property \App\Models\Omop\Concept $ancestor
 * @property \App\Models\Omop\Concept $descendant
 */
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

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'ancestor_concept_id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'descendant_concept_id');
    }
}
