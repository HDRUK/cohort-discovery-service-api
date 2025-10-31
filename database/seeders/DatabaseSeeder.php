<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

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

        Artisan::call('passport:install', ['--force' => true]);

        $this->call([
            CustodianSeeder::class,
            CollectionHostSeeder::class,
            CollectionSeeder::class,
            QuerySeeder::class,
            TaskSeeder::class,
        ]);
    }
}
