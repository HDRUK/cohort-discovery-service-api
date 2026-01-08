<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            WorkgroupSeeder::class,
            RolesAndPermissionsSeeder::class,
            StateSeeder::class,
            CustodianSeeder::class,
        ]);

    }
}
