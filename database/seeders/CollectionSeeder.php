<?php

namespace Database\Seeders;

use App\Enums\CollectionStatus;
use App\Enums\FrequencyMode;
use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\CollectionConfig;
use App\Models\CollectionHost;
use App\Models\CollectionHostHasCollection;
use App\Models\Custodian;
use App\Models\Distribution;
use App\Services\QueryContext\QueryContextType;
use Illuminate\Database\Seeder;

class CollectionSeeder extends Seeder
{
    public function run(): void
    {

        $this->seedCollectionWithDemographics(
            name: 'COVID-19 Antibody CKD Dataset',
            pid: 'a6c4f998-b837-4177-8e42-b941433abf44',
            url: null,
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0,
            status: CollectionStatus::ACTIVE->value
        );

        $this->seedCollectionWithDemographics(
            name: 'Various Conditions Dataset',
            pid: '8b9d64b5-c840-426e-bf6f-fdb50fd0f93a',
            url: null,
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0,
            status: CollectionStatus::ACTIVE->value
        );

        $this->seedCollectionWithDemographics(
            name: 'SARs-CoV-2 Symptoms Dataset',
            pid: 'accbd4a4-7e37-41e8-93de-eb1a3642e683',
            url: null,
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0,
            status: CollectionStatus::ACTIVE->value
        );

        $this->seedCollectionWithDemographics(
            name: 'COVID-19 Antibody and Symptoms Dataset',
            pid: 'a397a685-cbe2-4424-9c30-a9f37e6f2db7',
            url: null,
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0,
            status: CollectionStatus::ACTIVE->value
        );
    }

    private function seedCollectionWithDemographics(string $name, string $pid, ?string $url, QueryContextType $type, int $maleCount, int $femaleCount, int $status): void
    {
        $custodianId = Custodian::first()->id;
        $collection = Collection::create([
            'name' => $name,
            'pid' => $pid,
            'url' => $url,
            'type' => $type,
            'custodian_id' => $custodianId,
            'status' => $status,
        ]);

        // Create two CollectionConfig records for the above Collection
        // to mimic the distribution and generic query types
        $types = [TaskType::A, TaskType::B];
        $frequencyMode = FrequencyMode::WEEKLY->value; // Weekly
        $frequencyRun = 7; // ...on Sunday's
        foreach ($types as $t) {
            CollectionConfig::create([
                'collection_id' => $collection->id,
                'run_time_hour' => 23,
                'run_time_minute' => 59,
                'frequency_mode' => $frequencyMode,
                'run_time_frequency' => $frequencyRun,
                'enabled' => 1,
                'type' => $t,
            ]);

            $frequencyMode++; // Add another in monthly mode
            $frequencyRun = 1; // First week of the month
        }

        $collectionHost = CollectionHost::firstOrCreate([
            'name' => 'default-seeded-collection-host',
            'query_context_type' => 'bunny',
            'client_id' => 'ada604e0a5102c99e1cc989a97ae5da7cecd1edb01ca9d4b76be625dacad1107',
            'client_secret' => '00b261878aaf222f23becaec888b8b2907488bf3b4cfc5088482c68a841a6eb8',
            'custodian_id' => $custodianId,
        ]);

        CollectionHostHasCollection::create([
            'collection_host_id' => $collectionHost->id,
            'collection_id' => $collection->id,
        ]);

        $distributions = [
            ['name' => 'Male', 'description' => 'count of males', 'count' => $maleCount],
            ['name' => 'Female', 'description' => 'count of females', 'count' => $femaleCount],
            ['name' => 'SEX', 'description' => 'total count', 'count' => $maleCount + $femaleCount],
        ];

        foreach ($distributions as $dist) {
            if ($dist['count'] < 1) {
                continue;
            }
            Distribution::create([
                'collection_id' => $collection->id,
                'category' => 'DEMOGRAPHICS',
                ...$dist,
            ]);
        }
    }
}
