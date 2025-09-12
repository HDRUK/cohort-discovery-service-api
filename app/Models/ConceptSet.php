<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ConceptSet extends Model
{
    /** @use HasFactory<\Database\Factories\ConceptSetFactory> */
    use HasFactory;

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
