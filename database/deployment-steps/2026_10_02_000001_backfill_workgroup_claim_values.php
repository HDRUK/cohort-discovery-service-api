<?php

use App\DeploymentSteps\DeploymentStep;

/**
 * Backfill claim_value on the six categorical workgroups.
 *
 * WorkgroupSeeder uses Workgroup::create(), so it cannot safely re-run on existing
 * environments. The claim_value column was added after the initial workgroups table
 * seeding, so all nine rows in deployed databases currently have claim_value = null.
 *
 * The six categorical workgroups (NON-UK-INDUSTRY, NON-UK-RESEARCH, UK-INDUSTRY,
 * UK-RESEARCH, NHS-SDE, OTHER) need their claim_value set to match their slugs, or
 * standalone SSO workgroup claim-mapping will silently do nothing.
 *
 * ADMIN, DEFAULT, CUSTODIAN are deliberately left with claim_value = null — see
 * WorkgroupSeeder.php for why.
 */
return new class () extends DeploymentStep {
    public function handle(): void
    {
        $this->info('Backfilling workgroup claim_values...');

        $workgroups = [
            'NON-UK-INDUSTRY' => 'non-uk-industry',
            'NON-UK-RESEARCH' => 'non-uk-research',
            'OTHER' => 'other',
            'UK-INDUSTRY' => 'uk-industry',
            'UK-RESEARCH' => 'uk-research',
            'NHS-SDE' => 'nhs-sde',
        ];

        foreach ($workgroups as $name => $claimValue) {
            \App\Models\Workgroup::where('name', $name)->update(['claim_value' => $claimValue]);
            $this->info("  Updated {$name} -> {$claimValue}");
        }

        $this->info('Backfilled workgroup claim_values.');
    }
};
