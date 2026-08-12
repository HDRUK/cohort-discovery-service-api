<?php

use App\DeploymentSteps\DeploymentStep;

/**
 * Seed feature flags on deploy so newly introduced Pennant flags (e.g. the
 * query-builder location/death switches) are registered in the features table
 * without a manual db:seed.
 *
 * FeatureSeeder is idempotent: it only activates/deactivates flags that are not
 * already present, so existing flag states set by admins are preserved.
 */
return new class () extends DeploymentStep {
    public function handle(): void
    {
        $this->info('Seeding feature flags (FeatureSeeder)...');

        $this->call('db:seed', [
            '--class' => 'Database\\Seeders\\FeatureSeeder',
            '--force' => true,
        ]);

        $this->info('Seeded feature flags.');
    }
};
