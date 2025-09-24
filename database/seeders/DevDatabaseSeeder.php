<?php

namespace Database\Seeders;

use App\Models\Custodian;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DevDatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            WorkgroupSeeder::class,
            RolesAndPermissionsSeeder::class,
        ]);

        Custodian::create([
            'name' => 'Health Data Research UK',
            'gateway_team_name' => 'Health Data Research UK',
            'gateway_team_id' => env('GATEWAY_HDR_TEAM_ID', null),
            'pid' => Str::uuid(),
        ]);

        $this->call([
            CollectionSeeder::class,
        ]);
    }
}
