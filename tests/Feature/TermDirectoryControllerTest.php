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
    private const CONCEPT_ID_GENDER = 8507;

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

        // The job that builds the view looks for ResultFiles.
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

    public function test_search_by_concept_name_with_no_match_returns_empty(): void
    {
        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?concept_name=thisdoesnotexist');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_text_search_term_sent_as_concept_id_still_returns_name_matches(): void
    {
        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?concept_name=hypertension&concept_id=hypertension');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.total'));
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson(self::BASE_URL);

        $response->assertUnauthorized();
    }

    public function test_domain_filter_restricts_results(): void
    {
        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?domain_id=Condition');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.total'));

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?domain_id=Observation');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_domain_id_in_filter_returns_rows_from_any_listed_domain(): void
    {
        $collection = Collection::first();
        $resultFileId = DB::table('result_files')->value('id');

        DB::table('distributions')->insert([
            'collection_id'  => $collection->id,
            'result_file_id' => $resultFileId,
            'concept_id'     => self::CONCEPT_ID_GENDER,
            'count'          => 5,
            'name'           => '8507',
            'category'       => 'Gender',
            'description'    => 'MALE',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        RefreshLatestDistributionsView::dispatchSync();

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?domain_id__in=Gender,Condition');

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.total'));

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?domain_id__in=Gender,Race,Ethnicity');

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('Gender', $response->json('data.data.0.domain_id'));
    }

    public function test_response_shape_has_expected_fields(): void
    {
        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?concept_name=hypertension');

        $response->assertOk();

        $item = $response->json('data.data.0');
        $this->assertArrayHasKey('concept_id', $item);
        $this->assertArrayHasKey('concept_name', $item);
        $this->assertArrayHasKey('domain_id', $item);
        $this->assertArrayHasKey('count', $item);
        $this->assertArrayHasKey('ncollections', $item);
    }

    public function test_non_admin_without_collection_access_sees_no_results(): void
    {
        $basicUser = User::factory()->create();

        $response = $this->actingAsJwt($basicUser)
            ->getJson(self::BASE_URL . '?concept_name=hypertension');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }
}
