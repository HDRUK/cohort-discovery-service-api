<?php

namespace Database\Seeders;

use App\Models\Workgroup;
use Illuminate\Database\Seeder;

class WorkgroupSeeder extends Seeder
{
    private array $workgroups = [
        'ADMIN' => null,          // Null so ClaimMapper::syncWorkgroups() can never auto-assign
        'DEFAULT' => null,        // a user into privileged workgroups from IdP claims; only
        'CUSTODIAN' => null,      // admin-assigned membership is possible. Integrated-mode auth
                                  // (config/claimsaccesscontrol.php) uses a separate name-based
                                  // matching strategy; reconciling the two is deferred (DP-976).

        'NON-UK-INDUSTRY' => 'non-uk-industry',
        'NON-UK-RESEARCH' => 'non-uk-research',
        'OTHER' => 'other',
        'UK-INDUSTRY' => 'uk-industry',
        'UK-RESEARCH' => 'uk-research',
        'NHS-SDE' => 'nhs-sde',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->workgroups as $name => $claimValue) {
            Workgroup::create([
                'name' => $name,
                'active' => 1,
                'claim_value' => $claimValue,
            ]);
        }
    }
}
