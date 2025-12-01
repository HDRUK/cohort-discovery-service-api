<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Laravel\Passport\Client;
use Str;

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
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        if (! Client::where('provider', 'users')->exists()) {
            $client = Client::create([
                'owner_type' => null,
                'owner_id' => null,
                'secret' => Str::random(40),
                'name' => 'ProjectDaphne',
                'provider' => 'users',
                'redirect_uris' => [],
                'grant_types' => ['personal_access'],
                'revoked' => 0,
            ]);
        }

        $this->call([
            StateSeeder::class,
            CustodianSeeder::class,
            QuerySeeder::class,
            TaskSeeder::class,
        ]);
    }
}
