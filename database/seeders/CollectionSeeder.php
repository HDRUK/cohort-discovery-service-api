<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\CollectionHostHasCollection;
use App\Models\Custodian;
use App\Models\Distribution;
use App\Services\QueryContext\QueryContextType;

class CollectionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCollectionWithDemographics(
            name: 'Bunny Example',
            pid: 'db6d9b451b818ccc9a449383f2f0c450',
            url: null,
            type: QueryContextType::Bunny,
            maleCount: 560,
            femaleCount: 570
        );

        $this->seedCollectionWithDemographics(
            name: 'OMOP Synthea 1k',
            pid: '9de96ebd8d30dd931f9b90d2615c4b9d',
            url: null,
            type: QueryContextType::Bunny,
            maleCount: 200,
            femaleCount: 1000
        );

        $this->seedCollectionWithDemographics(
            name: 'Mock Dataset 250k',
            pid: '196b0f14eba66e10fba74dbf9e99c22f',
            url: null,
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0
        );

        $this->seedCollectionWithDemographics(
            name: 'Mock Covid Dataset 5M',
            pid: '43874274f7be1df2959b29c4b5afba47',
            url: null,
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0
        );
    }

    private function seedCollectionWithDemographics(string $name, string $pid, ?string $url, QueryContextType $type, int $maleCount, int $femaleCount): void
    {
        $custodianId = Custodian::first()->id;
        $collection = Collection::create([
            'name' => $name,
            'pid' => $pid,
            'url' => $url,
            'type' => $type,
            'custodian_id' => $custodianId,
        ]);

        $collectionHost = CollectionHost::where('custodian_id', $custodianId)->first();

        CollectionHostHasCollection::create([
            'collection_host_id' => $collectionHost->id,
            'collection_id' => $collection->id
        ]);

        $distributions = [
            ['name' => 'Male', 'description' => 'count of males', 'count' => $maleCount],
            ['name' => 'Female', 'description' => 'count of females', 'count' => $femaleCount],
            ['name' => 'SEX', 'description' => 'total count', 'count' => $maleCount + $femaleCount],
        ];


        foreach ($distributions as $dist) {
            Distribution::create([
                'collection_id' => $collection->id,
                'category' => 'DEMOGRAPHICS',
                ...$dist
            ]);
        }
    }
}
