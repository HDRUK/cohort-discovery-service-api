<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
