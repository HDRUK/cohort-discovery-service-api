<?php

use App\DeploymentSteps\DeploymentStep;
use App\Jobs\RefreshLatestDistributionsView;

/**
 * Build the `latest_distributions` VIEW and fill its materialised snapshot table
 * on deploy.
 *
 * The materialised table is created empty by migration; it is otherwise only
 * refilled when a distribution file is processed or the domain-source flag flips.
 * Without this step a fresh deploy would serve an empty Term Directory until the
 * next distribution arrives. Runs the refresh synchronously so the table is
 * populated by the time deploy:run returns.
 */
return new class () extends DeploymentStep {
    public function handle(): void
    {
        $this->info('Materialising latest_distributions view...');

        RefreshLatestDistributionsView::dispatchSync();

        $this->info('Materialised latest_distributions view.');
    }
};
