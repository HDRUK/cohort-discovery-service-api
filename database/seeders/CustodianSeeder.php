<?php

namespace Database\Seeders;

use App\Models\Custodian;
use Illuminate\Database\Seeder;
use DB;

class CustodianSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        Custodian::factory()->count(10)->create();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
