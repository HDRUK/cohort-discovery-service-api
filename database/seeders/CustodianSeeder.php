<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Custodian;

class CustodianSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Custodian::factory()->count(10)->create();
    }
}
