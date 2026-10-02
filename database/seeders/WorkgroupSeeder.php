<?php

namespace Database\Seeders;

use App\Models\Workgroup;
use Illuminate\Database\Seeder;

class WorkgroupSeeder extends Seeder
{
    private array $workgroups = [
        'ADMIN' => null,
        'DEFAULT' => null,
        'CUSTODIAN' => null,
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
