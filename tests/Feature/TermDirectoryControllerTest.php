<?php

namespace Tests\Feature;

use App\Jobs\RefreshLatestDistributionsView;
use App\Models\Collection;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
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

        // Default to the reported/origin domain so tests are isolated from any
        // flag state persisted by another test.
        Feature::deactivate('distribution-use-central-domain');

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
        $this->assertArrayHasKey('central_domain_id', $item);
        $this->assertArrayHasKey('reported_domains', $item);
        $this->assertIsArray($item['reported_domains']);
        $this->assertArrayHasKey('domain_mismatch', $item);
        $this->assertArrayHasKey('count', $item);
        $this->assertArrayHasKey('ncollections', $item);
    }

    public function test_domain_defaults_to_reported_category_over_central(): void
    {
        $collection = Collection::first();
        $resultFileId = DB::table('result_files')->value('id');

        // Concept 8507's central OMOP domain is 'Gender', but this custodian
        // reported it under 'Observation'. By default we trust the reported domain.
        DB::table('distributions')->insert([
            'collection_id'  => $collection->id,
            'result_file_id' => $resultFileId,
            'concept_id'     => self::CONCEPT_ID_GENDER,
            'count'          => 5,
            'name'           => '8507',
            'category'       => 'Observation',
            'description'    => 'MALE',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        RefreshLatestDistributionsView::dispatchSync();

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?domain_id=Observation');
        $response->assertOk();
        $ids = array_column($response->json('data.data'), 'concept_id');
        $this->assertContains(self::CONCEPT_ID_GENDER, $ids);

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?domain_id=Gender');
        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_flag_switches_domain_to_central(): void
    {
        $collection = Collection::first();
        $resultFileId = DB::table('result_files')->value('id');

        DB::table('distributions')->insert([
            'collection_id'  => $collection->id,
            'result_file_id' => $resultFileId,
            'concept_id'     => self::CONCEPT_ID_GENDER,
            'count'          => 5,
            'name'           => '8507',
            'category'       => 'Observation',
            'description'    => 'MALE',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // With the flag on, the concept surfaces under its central domain
        // (Gender), not the reported one (Observation).
        Feature::activate('distribution-use-central-domain');
        RefreshLatestDistributionsView::dispatchSync();

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?domain_id=Gender');
        $response->assertOk();
        $ids = array_column($response->json('data.data'), 'concept_id');
        $this->assertContains(self::CONCEPT_ID_GENDER, $ids);

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?domain_id=Observation');
        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_null_or_zero_concept_ids_are_excluded_from_the_view(): void
    {
        $collection = Collection::first();
        $resultFileId = DB::table('result_files')->value('id');

        // concept_id 0 is the OMOP "No matching concept" placeholder and exists in
        // the vocabulary, so without an explicit guard it would join into the view.
        DB::connection('omop')->table('concept')->insert([
            'concept_id'       => 0,
            'concept_name'     => 'No matching concept',
            'domain_id'        => 'Metadata',
            'vocabulary_id'    => 'None',
            'concept_class_id' => 'Undefined',
            'standard_concept' => null,
            'concept_code'     => 'No matching concept',
            'valid_start_date' => '1970-01-01',
            'valid_end_date'   => '2099-12-31',
            'invalid_reason'   => null,
        ]);

        try {
            DB::table('distributions')->insert([
                [
                    'collection_id'  => $collection->id,
                    'result_file_id' => $resultFileId,
                    'concept_id'     => 0,
                    'count'          => 7,
                    'name'           => '0',
                    'category'       => 'Condition',
                    'description'    => 'zero concept',
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ],
                [
                    'collection_id'  => $collection->id,
                    'result_file_id' => $resultFileId,
                    'concept_id'     => null,
                    'count'          => 9,
                    'name'           => 'NULLCONCEPT',
                    'category'       => 'Condition',
                    'description'    => 'null concept',
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ],
            ]);

            RefreshLatestDistributionsView::dispatchSync();

            $this->assertFalse(
                DB::table('latest_distributions')->where('concept_id', 0)->exists(),
                'concept_id 0 should be excluded from latest_distributions'
            );
            $this->assertFalse(
                DB::table('latest_distributions')->whereNull('concept_id')->exists(),
                'null concept_id should be excluded from latest_distributions'
            );
        } finally {
            DB::connection('omop')->table('concept')->where('concept_id', 0)->delete();
        }
    }

    public function test_non_admin_without_collection_access_sees_no_results(): void
    {
        $basicUser = User::factory()->create();

        $response = $this->actingAsJwt($basicUser)
            ->getJson(self::BASE_URL . '?concept_name=hypertension');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_collection_pid_filter_restricts_results(): void
    {
        $second = Collection::factory()->create();
        $task = Task::factory()->create(['collection_id' => $second->id]);

        $resultFileId = DB::table('result_files')->insertGetId([
            'task_id'       => $task->id,
            'collection_id' => $second->id,
            'path'          => 'test/path',
            'file_name'     => 'code.distribution',
            'status'        => 'done',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('distributions')->insert([
            'collection_id'  => $second->id,
            'result_file_id' => $resultFileId,
            'concept_id'     => self::CONCEPT_ID_A,
            'count'          => 7,
            'name'           => 'CONCEPT_A',
            'category'       => 'Condition',
            'description'    => 'Concept A description',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        RefreshLatestDistributionsView::dispatchSync();

        $response = $this->actingAsJwt($this->user)->getJson(self::BASE_URL);

        $response->assertOk();
        $rows = collect($response->json('data.data'))->keyBy('concept_id');
        $this->assertEquals(17, $rows[self::CONCEPT_ID_A]['count']);
        $this->assertEquals(2, $rows[self::CONCEPT_ID_A]['ncollections']);

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL . '?collection_pid[]=' . $second->pid);

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals(self::CONCEPT_ID_A, $response->json('data.data.0.concept_id'));
        $this->assertEquals(7, $response->json('data.data.0.count'));
        $this->assertEquals(1, $response->json('data.data.0.ncollections'));
    }

    public function test_collection_pid_filter_combines_with_domain_filter(): void
    {
        $collection = Collection::firstOrFail();
        $url = self::BASE_URL . '?collection_pid[]=' . $collection->pid;

        $response = $this->actingAsJwt($this->user)
            ->getJson($url . '&domain_id=Condition');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.total'));

        $response = $this->actingAsJwt($this->user)
            ->getJson($url . '&domain_id=Observation');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_collection_pid_of_invisible_collection_is_ignored(): void
    {
        $collection = Collection::firstOrFail();

        $basicUser = User::factory()->create();

        $response = $this->actingAsJwt($basicUser)
            ->getJson(self::BASE_URL . '?collection_pid[]=' . $collection->pid);

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }
}
