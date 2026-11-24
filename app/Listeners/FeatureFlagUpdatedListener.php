<?php

namespace App\Listeners;

use App\Jobs\RefreshLatestDistributionsView;
use Laravel\Pennant\Events\FeatureUpdated;

/**
 * Central switchboard for reacting to GLOBAL feature-flag changes — the global
 * (null) scope set by FeatureController. Per-user and per-workgroup overrides
 * also fire FeatureUpdated, with a non-null scope; this listener intentionally
 * ignores those. Add a new case per flag that needs a side effect — no extra listener
 * class required.
 */
class FeatureFlagUpdatedListener
{
    public function handle(FeatureUpdated $event): void
    {
        if ($event->scope !== null) {
            return;
        }

        match ($event->feature) {
            // Rebuild the view so the effective domain_id switches source.
            'distribution-use-central-domain' => RefreshLatestDistributionsView::dispatch(),

            default => null,
        };
    }
}
