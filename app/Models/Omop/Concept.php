<?php

namespace App\Models\Omop;

use App\Models\Distribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Hdruk\LaravelSearchAndFilter\Traits\Search;

/**
 * @property int $concept_id
 * @property int $domain_id
 * @property string|int $concept_code
 * @property int $vocabulary_id
 * @property \App\Models\Omop\Concept $ancestors
 * @property \App\Models\Omop\Concept $descendants
 * @property \App\Models\Distribution $distributions
 */
class Concept extends Model
{
    use Search;

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

    protected static array $searchableColumns = [
        'concept_name',
    ];

    protected static array $sortableColumns = [
        'concept_id',
        'concept_name',
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

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class, 'concept_id', 'concept_id');
    }

    public function scopeInDistribution($query)
    {
        $conceptIds = Distribution::distinct()
            ->whereNotNull('concept_id')
            ->pluck('concept_id')
            ->all();

        return $query->whereIn('concept_id', $conceptIds);
    }
}
