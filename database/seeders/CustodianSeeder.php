<?php

namespace Database\Seeders;

use App\Models\Custodian;
use Illuminate\Database\Seeder;

class CustodianSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Custodian::truncate();
        Custodian::factory()->count(10)->create();
    }
}
