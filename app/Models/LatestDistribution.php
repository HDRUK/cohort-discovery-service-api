<?php

namespace App\Models;

use App\Models\Omop\Concept;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LatestDistribution extends Model
{
    use Filter;
    use Search;

    protected $table = 'latest_distributions';

    public $timestamps = false;

    protected $casts = [
        'count' => 'integer',
        'ncollections' => 'integer',
    ];

    protected static $searchableColumns = [
        'concept_id',
        'concept_name' => ['concept_name', 'concept_id'],
    ];

    protected static $sortableColumns = [
        'concept_id',
        'concept_name',
        'count',
        'ncollections',
    ];

    protected static $filterableColumns = [
        'domain_id',
        'concept_id',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'concept_id', 'concept_id');
    }
}
