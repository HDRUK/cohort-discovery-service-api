<?php

namespace Database\Seeders;

use App\Enums\FrequencyMode;
use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\CollectionConfig;
use App\Models\CollectionHost;
use App\Models\CollectionHostHasCollection;
use App\Models\Custodian;
use App\Models\Distribution;
use App\Models\Workgroup;
use App\Models\WorkgroupHasCollection;
use App\Services\QueryContext\QueryContextType;
use Hdruk\LaravelModelStates\Models\State;
use Illuminate\Database\Seeder;

class CollectionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCollectionWithDemographics(
            name: 'COVID-19 Antibody CKD Dataset',
            pid: '00000000-0000-0000-0000-000000000000',
            url: 'http://example.com',
            description: 'A demo dataset',
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0,
            statusSlug: Collection::STATUS_ACTIVE,
        );

        $this->seedCollectionWithDemographics(
            name: 'Various Conditions Dataset',
            pid: '00000000-0000-0000-0000-000000000001',
            url: 'http://example.com',
            description: 'A demo dataset',
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0,
            statusSlug: Collection::STATUS_ACTIVE,
        );

        $this->seedCollectionWithDemographics(
            name: 'SARs-CoV-2 Symptoms Dataset',
            pid: '00000000-0000-0000-0000-000000000002',
            url: 'http://example.com',
            description: 'A demo dataset',
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0,
            statusSlug: Collection::STATUS_ACTIVE,
        );

        $this->seedCollectionWithDemographics(
            name: 'COVID-19 Antibody and Symptoms Dataset',
            pid: '00000000-0000-0000-0000-000000000003',
            url: 'http://example.com',
            description: 'A demo dataset',
            type: QueryContextType::Bunny,
            maleCount: 0,
            femaleCount: 0,
            statusSlug: Collection::STATUS_ACTIVE,
        );
    }

    private function seedCollectionWithDemographics(
        string $name,
        string $pid,
        ?string $url,
        ?string $description,
        QueryContextType $type,
        int $maleCount,
        int $femaleCount,
        string $statusSlug,
    ): void {
        $custodianId = Custodian::query()->firstOrFail()->id;

        $collection = Collection::create([
            'name' => $name,
            'pid' => $pid,
            'url' => $url,
            'description' => $description,
            'type' => $type,
            'custodian_id' => $custodianId,
        ]);

        $collection->modelState()->updateOrCreate(
            [],
            [
                'state_id' => $this->getStateIdBySlug($statusSlug),
            ],
        );

        $types = [TaskType::A, TaskType::B];
        $frequencyMode = FrequencyMode::WEEKLY->value;
        $frequencyRun = 6;

        foreach ($types as $t) {
            CollectionConfig::create([
                'collection_id' => $collection->id,
                'run_time_hour' => 23,
                'run_time_minute' => 59,
                'frequency_mode' => $frequencyMode,
                'run_time_frequency' => $frequencyRun,
                'enabled' => 1,
                'type' => $t->value,
            ]);

            $frequencyMode++;
            $frequencyRun = 1;
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

        $workgroupId = Workgroup::query()->inRandomOrder()->firstOrFail()->id;
        WorkgroupHasCollection::create([
            'workgroup_id' => $workgroupId,
            'collection_id' => $collection->id,
        ]);
    }

    private function getStateIdBySlug(string $slug): int
    {
        return State::query()
            ->where('slug', $slug)
            ->valueOrFail('id');
    }
}
