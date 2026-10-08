<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\Client;
use Illuminate\Support\Str;

class PersonalAccessClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Client::where('provider', 'users')->exists()) {
            Client::create([
                'owner_type' => null,
                'owner_id' => null,
                'secret' => Str::random(40),
                'name' => 'CohortDiscoveryService',
                'provider' => 'users',
                'redirect_uris' => [],
                'grant_types' => ['personal_access'],
                'revoked' => 0,
            ]);
        }
    }
}
