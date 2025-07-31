<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Collection;
use App\Services\QueryContext\QueryContextType;

class CollectionSeeder extends Seeder
{
    public function run(): void
    {
        Collection::create([
            'name' => 'Test Omop Collection - Small ',
            'pid' => 'db6d9b451b818ccc9a449383f2f0c450',
            'type' => QueryContextType::Bunny,
        ]);

        Collection::create([
            'name' => 'Test Omop Collection - Large ',
            'pid' => '9de96ebd8d30dd931f9b90d2615c4b9d',
            'type' => QueryContextType::Bunny,
        ]);
    }
}
