<?php

namespace Database\Seeders;

use App\Models\Workgroup;
use Illuminate\Database\Seeder;

class WorkgroupSeeder extends Seeder
{
    private array $workgroups = [
        'ADMIN',
        'DEFAULT',
        'CUSTODIAN',
        'NON-UK-INDUSTRY',
        'NON-UK-RESEARCH',
        'OTHER',
        'UK-INDUSTRY',
        'UK-RESEARCH',
        'NHS-SDE',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->workgroups as $w) {
            Workgroup::create([
                'name' => $w,
                'active' => 1,
            ]);
        }
    }
}
