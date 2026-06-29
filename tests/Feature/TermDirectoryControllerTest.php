<?php

namespace Tests\Feature;

use App\Jobs\RefreshLatestDistributionsView;
use App\Models\Collection;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TermDirectoryControllerTest extends TestCase
{
    private const BASE_URL = '/api/v1/term-directory';

    // concept_ids from the MinimalOmopSeeder (minimal_concept.csv)
    // which is ran by the RefreshDatabaseLite trait on test setup.
    private const CONCEPT_ID_A = 1075886;
    private const CONCEPT_ID_B = 1075887;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('distributions')->truncate();
        DB::table('result_files')->truncate();
        DB::table('tasks')->truncate();
        Collection::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $collection = Collection::factory()->create();

        // ResultFile requires a Task, so we need to create those first.
        $task = Task::factory()->create(['collection_id' => $collection->id]);

        $resultFileId = DB::table('result_files')->insertGetId([
            'task_id'       => $task->id,
            'collection_id' => $collection->id,
            'path'          => 'test/path',
            'file_name'     => 'code.distribution',
            'status'        => 'done',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('distributions')->insert([
            [
                'collection_id'  => $collection->id,
                'result_file_id' => $resultFileId,
                'concept_id'     => self::CONCEPT_ID_A,
                'count'          => 10,
                'name'           => 'CONCEPT_A',
                'category'       => 'Condition',
                'description'    => 'Concept A description',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'collection_id'  => $collection->id,
                'result_file_id' => $resultFileId,
                'concept_id'     => self::CONCEPT_ID_B,
                'count'          => 50,
                'name'           => 'CONCEPT_B',
                'category'       => 'Condition',
                'description'    => 'Concept B description',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);

        // Build the latest_distributions view from the data we just inserted.
        RefreshLatestDistributionsView::dispatchSync();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_search_by_concept_name_returns_matching_rows(): void
    {
        // Both seeded concepts contain "hypertension" in their name.
        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?concept_name=hypertension');

        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertCount(2, $data);

        $names = array_column($data, 'concept_name');
        $this->assertContains('Hypertension in chronic kidney disease stage 3B due to type 1 diabetes mellitus', $names);
        $this->assertContains('Hypertension in chronic kidney disease stage 3A due to type 1 diabetes mellitus', $names);
    }
}
