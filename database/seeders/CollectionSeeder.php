<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Collection;
use App\Models\Distribution;
use App\Services\QueryContext\QueryContextType;

class CollectionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCollectionWithDemographics(
            name: 'Test Omop Collection - Small',
            pid: 'db6d9b451b818ccc9a449383f2f0c450',
            type: QueryContextType::Bunny,
            maleCount: 560,
            femaleCount: 570
        );

        $this->seedCollectionWithDemographics(
            name: 'Synthea 1k',
            pid: '9de96ebd8d30dd931f9b90d2615c4b9d',
            type: QueryContextType::Bunny,
            maleCount: 200,
            femaleCount: 1000
        );
    }

    private function seedCollectionWithDemographics(string $name, string $pid, QueryContextType $type, int $maleCount, int $femaleCount): void
    {
        $collection = Collection::create([
            'name' => $name,
            'pid' => $pid,
            'type' => $type,
        ]);

        $distributions = [
            ['name' => 'M', 'description' => 'Male', 'count' => $maleCount],
            ['name' => 'F', 'description' => 'Female', 'count' => $femaleCount],
            ['name' => 'ALL', 'description' => 'all', 'count' => $maleCount + $femaleCount],
        ];

        return;
        foreach ($distributions as $dist) {
            Distribution::create([
                'collection_id' => $collection->id,
                'category' => 'demographics',
                ...$dist
            ]);
        }
    }
}
