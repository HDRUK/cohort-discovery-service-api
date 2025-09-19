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
            name: 'COVID-19 Antibody CKD dataset',
            pid: 'a6c4f998-b837-4177-8e42-b941433abf44',
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

        $collectionHost = CollectionHost::firstOrCreate([
            'name' => 'default-seeded-collection-host',
            'query_context_type' => 'bunny',
            'client_id' => 'ada604e0a5102c99e1cc989a97ae5da7cecd1edb01ca9d4b76be625dacad1107',
            'client_secret' => '00b261878aaf222f23becaec888b8b2907488bf3b4cfc5088482c68a841a6eb8',
            'custodian_id' => $custodianId
        ]);

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
            if ($dist['count'] < 1) continue;
            Distribution::create([
                'collection_id' => $collection->id,
                'category' => 'DEMOGRAPHICS',
                ...$dist
            ]);
        }
    }
}
