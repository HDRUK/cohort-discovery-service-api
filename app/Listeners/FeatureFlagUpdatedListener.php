<?php

namespace App\Listeners;

use App\Jobs\RefreshLatestDistributionsView;
use Laravel\Pennant\Events\FeatureUpdatedForAllScopes;

/**
 * Central switchboard for reacting to GLOBAL feature-flag changes — i.e. flags
 * toggled "for everyone" (FeatureController's activateForEveryone /
 * deactivateForEveryone, which fire FeatureUpdatedForAllScopes). This is the only
 * way flags are flipped in-app, and the app resolves every flag on the global
 * (null) scope, so this listener intentionally does NOT react to per-scope changes
 * (Pennant's FeatureUpdated event, e.g. a scoped Feature::activate() from a seeder
 * or tinker). Add a new case per flag that needs a side effect — no extra listener
 * class required.
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
