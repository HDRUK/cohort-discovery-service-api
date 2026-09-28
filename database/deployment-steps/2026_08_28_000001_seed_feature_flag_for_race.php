<?php

use App\DeploymentSteps\DeploymentStep;

/**
 * Re-run FeatureSeeder on deploy so the newly added `query-builder-use-race`
 * flag is registered in the features table.
 *
 * Deployment steps are run-once, so the earlier seed_feature_flags steps will
 * not re-run to pick up flags added afterwards - a fresh step is required.
 * FeatureSeeder is idempotent (only touches flags not already present), so this
 * safely adds the new flag without disturbing existing admin-set states.
 */
return new class () extends DeploymentStep {
    public function handle(): void
    {
        $this->info('Re-seeding feature flags (FeatureSeeder) for query-builder-use-race flag...');

        $this->call('db:seed', [
            '--class' => 'Database\\Seeders\\FeatureSeeder',
            '--force' => true,
        ]);

        $this->info('Re-seeded feature flags.');
    }
};
