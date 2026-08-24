<?php

namespace App\Models;

use App\Models\Omop\Concept;
use Hdruk\LaravelSearchAndFilter\Traits\Filter;
use Hdruk\LaravelSearchAndFilter\Traits\Search;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LatestDistribution extends Model
{
    use Filter;
    use Search;

    // Now reads the materialised snapshot table (refilled by RefreshLatestDistributionsView),
    // not the `latest_distributions` VIEW. The view is exposed via LatestDistributionView.
    // keeping both the table and view for now as backup and prod speed tests
    // - the materialised table is much faster
    protected $table = 'latest_distributions_materialised';

    public $timestamps = false;

    protected $casts = [
        'count' => 'integer',
        'ncollections' => 'integer',
        'domain_mismatch' => 'boolean',
    ];

    protected static $searchableColumns = [
        'concept_id',
        'concept_name',
    ];

    protected static $sortableColumns = [
        'count',
        'concept_id',
        'concept_name',
        'ncollections',
    ];

    protected static $filterableColumns = [
        'domain_id',
    ];

    /**
     * The term-directory query aggregates the per-collection reported domains into
     * a comma-separated string (GROUP_CONCAT); expose it as a list.
     */
    protected function reportedDomains(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null || $value === ''
                ? []
                : explode(',', $value),
        );
    }

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
