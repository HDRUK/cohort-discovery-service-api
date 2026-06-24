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

    protected static $searchableColumns = [
        'concept_id',
        'name',
        'description',
    ];

    protected static $sortableColumns = [
        'concept_id',
        'name',
        'count',
    ];

    protected static $filterableColumns = [
        'category',
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
