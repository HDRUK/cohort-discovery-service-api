<?php

use App\DeploymentSteps\DeploymentStep;

/**
 * Re-run FeatureSeeder on deploy so the newly added `click-tracking-anonymous`
 * flag (generic click tracking, DP-946 follow-up) is registered in the features
 * table.
 *
 * Deployment steps are run-once, so the earlier seed_feature_flags steps will
 * not re-run to pick up flags added afterwards - a fresh step is required.
 * FeatureSeeder is idempotent (only touches flags not already present), so this
 * safely adds the new flag without disturbing existing admin-set states.
 */
return new class () extends DeploymentStep {
    public function handle(): void
    {
        $this->info('Re-seeding feature flags (FeatureSeeder) for click-tracking-anonymous flag...');

        $this->call('db:seed', [
            '--class' => 'Database\\Seeders\\FeatureSeeder',
            '--force' => true,
        ]);

        $this->info('Re-seeded feature flags.');
    }
};
