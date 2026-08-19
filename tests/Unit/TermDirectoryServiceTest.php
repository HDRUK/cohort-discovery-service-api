<?php

namespace Tests\Unit;

use App\Jobs\RefreshLatestDistributionsView;
use App\Models\Collection;
use App\Models\Task;
use App\Models\User;
use App\Services\TermDirectory\TermDirectoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TermDirectoryServiceTest extends TestCase
{
    // concept_ids from the MinimalOmopSeeder (minimal_concept.csv).
    private const CONCEPT_ID_GENDER = 8507;
    private const CONCEPT_ID_RACE = 8527;

    private TermDirectoryService $service;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TermDirectoryService::class);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('distributions')->truncate();
        DB::table('result_files')->truncate();
        DB::table('tasks')->truncate();
        Collection::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->collection = Collection::factory()->create();

        // Only Gender, deliberately — several tests below rely on this
        // collection NOT reporting Race, so narrowing to it is meaningful.
        $this->seedDistributions($this->collection->id, [
            [8507, 'Gender', 'Male'],
        ]);

        RefreshLatestDistributionsView::dispatchSync();
    }

    /**
     * Seeds one result file (a collection's "latest successful concept result
     * file" is a single ofMany relation, so every concept for a collection
     * must share the same result_file_id — otherwise only the most recently
     * inserted one is picked up by the view refresh).
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $concepts  [concept_id, category, name]
     */
    private function seedDistributions(int $collectionId, array $concepts): void
    {
        $task = Task::factory()->create(['collection_id' => $collectionId]);

        $resultFileId = DB::table('result_files')->insertGetId([
            'task_id'       => $task->id,
            'collection_id' => $collectionId,
            'path'          => 'test/path',
            'file_name'     => 'code.distribution',
            'status'        => 'done',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('distributions')->insert(array_map(
            fn (array $c) => [
                'collection_id'  => $collectionId,
                'result_file_id' => $resultFileId,
                'concept_id'     => $c[0],
                'count'          => 10,
                'name'           => $c[2],
                'category'       => $c[1],
                'description'    => $c[2],
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            $concepts
        ));
    }

    public function test_admin_sees_concept_options_for_requested_domains(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Auth::setUser($admin);

        $raceCollection = Collection::factory()->create();
        $this->seedDistributions($raceCollection->id, [[8527, 'Race', 'White']]);
        RefreshLatestDistributionsView::dispatchSync();

        $conceptIds = $this->service
            ->conceptOptionsForDomains(['Gender', 'Race'])
            ->pluck('concept_id')
            ->all();

        $this->assertContains(self::CONCEPT_ID_GENDER, $conceptIds);
        $this->assertContains(self::CONCEPT_ID_RACE, $conceptIds);
    }

    public function test_non_admin_without_collection_access_sees_no_options(): void
    {
        $basicUser = User::factory()->create();
        Auth::setUser($basicUser);

        $options = $this->service->conceptOptionsForDomains(['Gender', 'Race']);

        $this->assertTrue($options->isEmpty());
    }

    public function test_explicit_collection_ids_never_widen_visibility(): void
    {
        $basicUser = User::factory()->create();
        Auth::setUser($basicUser);

        // The basic user cannot see $this->collection at all, so explicitly
        // requesting its id must not surface its concepts.
        $options = $this->service->conceptOptionsForDomains(['Gender', 'Race'], [$this->collection->id]);

        $this->assertTrue($options->isEmpty());
    }

    public function test_explicit_collection_ids_narrow_within_visible_set(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Auth::setUser($admin);

        $otherCollection = Collection::factory()->create();
        $this->seedDistributions($otherCollection->id, [[8527, 'Race', 'White']]);
        RefreshLatestDistributionsView::dispatchSync();

        // Admin can see both collections by default.
        $allConceptIds = $this->service
            ->conceptOptionsForDomains(['Gender', 'Race'])
            ->pluck('concept_id')
            ->all();
        $this->assertContains(self::CONCEPT_ID_GENDER, $allConceptIds);
        $this->assertContains(self::CONCEPT_ID_RACE, $allConceptIds);

        // Narrowing to only $this->collection (which never reported Race)
        // must exclude the Race concept the other collection reported.
        $narrowedConceptIds = $this->service
            ->conceptOptionsForDomains(['Gender', 'Race'], [$this->collection->id])
            ->pluck('concept_id')
            ->all();
        $this->assertContains(self::CONCEPT_ID_GENDER, $narrowedConceptIds);
        $this->assertNotContains(self::CONCEPT_ID_RACE, $narrowedConceptIds);
    }
}
