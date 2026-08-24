<?php

namespace App\Models;

/**
 * Reads the `latest_distributions` MySQL VIEW directly (the un-materialised source).
 *
 * Identical shape to LatestDistribution, which reads the materialised snapshot table.
 * Kept so the view remains queryable — useful for benchmarking the view against the
 * materialised table, and as a fallback if the table is stale.
 */
class LatestDistributionView extends LatestDistribution
{
    protected $table = 'latest_distributions';
}
