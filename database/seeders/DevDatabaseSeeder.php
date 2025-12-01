<?php

namespace Database\Seeders;

use App\Support\ApplicationMode;
use Illuminate\Database\Seeder;
use App\Models\Collection;
use App\Models\CollectionHost;
use DB;

class DevDatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {


        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Collection::truncate();
        CollectionHost::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->call([
            CollectionSeeder::class,
            CollectionHostSeeder::class,
        ]);

        if (ApplicationMode::isStandalone()) {
            $this->call([
                StandaloneDemoSeeder::class
            ]);
        }
    }
}
