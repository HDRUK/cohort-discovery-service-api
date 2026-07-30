<?php

namespace App\Listeners;

use App\Jobs\RefreshLatestDistributionsView;
use Laravel\Pennant\Events\FeatureUpdatedForAllScopes;

/**
 * Central switchboard for reacting to feature-flag changes (toggled for everyone,
 * e.g. via FeatureController). Add a new case per flag that needs a side effect —
 * no extra listener class required.
 */
class FeatureFlagUpdatedListener
{
    public function handle(FeatureUpdatedForAllScopes $event): void
    {
        match ($event->feature) {
            // Rebuild the view so the effective domain_id switches source.
            'distribution-use-central-domain' => RefreshLatestDistributionsView::dispatch(),

            default => null,
        };
    }
}
