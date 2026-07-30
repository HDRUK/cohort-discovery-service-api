<?php

namespace App\Listeners;

use App\Jobs\RefreshLatestDistributionsView;
use Laravel\Pennant\Events\FeatureUpdatedForAllScopes;

/**
 * Rebuilds the latest_distributions view whenever the domain-source feature flag
 * is toggled for everyone (the path used by FeatureController), so the effective
 * domain_id switches between the reported and central OMOP domain without a
 * manual refresh.
 */
class RefreshDistributionsOnDomainSourceChange
{
    public function handle(FeatureUpdatedForAllScopes $event): void
    {
        if ($event->feature !== 'distribution-use-central-domain') {
            return;
        }

        RefreshLatestDistributionsView::dispatch();
    }
}
