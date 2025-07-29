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
            'name' => 'Test Omop Collection',
            'pid' => 'db6d9b451b818ccc9a449383f2f0c450',
            'type' => QueryContextType::Bunny,
        ]);
    }
}
