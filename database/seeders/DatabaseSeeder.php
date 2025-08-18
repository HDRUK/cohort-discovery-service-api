<?php

namespace Database\Seeders;

use App\Models\User;
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
        ]);


        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            CustodianSeeder::class,
            CollectionSeeder::class,
            QuerySeeder::class,
            TaskSeeder::class,
            CollectionHostSeeder::class,
        ]);
    }
}
