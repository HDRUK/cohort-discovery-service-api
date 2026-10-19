<?php

use App\DeploymentSteps\DeploymentStep;

/**
 * Re-run FeatureSeeder so the features table matches the seeder's list.
 *
 * FeatureSeeder now registers flags it does not yet have and purges any it no
 * longer lists, so this both adds what claimsaccesscontrol.sync replaced them
 * with and removes the eight retired claim sync flags. Existing admin-set
 * states are left alone.
 *
 * Deployment steps are run-once and keyed on filename, so retiring a flag in
 * future needs an edit to FeatureSeeder plus a fresh copy of this step.
 */
return new class () extends DeploymentStep {
    public function handle(): void
    {
        $this->info('Re-seeding feature flags (FeatureSeeder)...');

        $this->call('db:seed', [
            '--class' => 'Database\\Seeders\\FeatureSeeder',
            '--force' => true,
        ]);

        $this->info('Re-seeded feature flags.');
    }
};
