<?php

use App\DeploymentSteps\DeploymentStep;
use Illuminate\Support\Facades\DB;

/**
 * Remove the feature flags that config/claimsaccesscontrol.php `sync` replaced.
 *
 * FeatureSeeder skips any flag already present in the features table, so
 * dropping these names from the seeder leaves the rows behind on every deployed
 * environment, where they keep showing up in the admin flag list as though they
 * still govern something.
 */
return new class () extends DeploymentStep {
    private array $flags = [
        'integrated-sync-workgroups-every-request',
        'integrated-sync-workgroups-first-login',
        'integrated-sync-workgroups-authoritative',
        'integrated-ensure-default-wgs',
        'integrated-sync-sde-wgs-from-claim',
        'integrated-sync-roles-every-request',
        'integrated-sync-custodians-every-request',
        'sso-ensure-defaults-on-jit',
    ];

    public function handle(): void
    {
        $this->info('Purging claim sync feature flags superseded by claimsaccesscontrol.sync...');

        $deleted = DB::table('features')->whereIn('name', $this->flags)->delete();

        $this->info("Purged {$deleted} feature flag row(s).");
    }
};
