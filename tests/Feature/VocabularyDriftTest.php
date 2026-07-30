<?php

namespace Tests\Feature;

use App\Jobs\RefreshLatestDistributionsView;
use App\Models\Collection;
use App\Models\Task;
use App\Models\User;
use App\Services\VocabularyDrift\VocabularyDriftService;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class VocabularyDriftTest extends TestCase
{
    // concept_ids from the MinimalOmopSeeder (minimal_concept.csv).
    private const CONCEPT_MATCH = 1075886;   // central domain: Condition
    private const CONCEPT_MISMATCH = 8507;   // central domain: Gender

    private User $user;

    private Collection $collection;

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

        Feature::deactivate('distribution-use-central-domain');

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->collection = Collection::factory()->create();

        $task = Task::factory()->create(['collection_id' => $this->collection->id]);

        $resultFileId = DB::table('result_files')->insertGetId([
            'task_id'       => $task->id,
            'collection_id' => $this->collection->id,
            'path'          => 'test/path',
            'file_name'     => 'code.distribution',
            'status'        => 'done',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('distributions')->insert([
            [
                // Reported domain matches central (Condition) → no drift.
                'collection_id'  => $this->collection->id,
                'result_file_id' => $resultFileId,
                'concept_id'     => self::CONCEPT_MATCH,
                'count'          => 10,
                'name'           => (string) self::CONCEPT_MATCH,
                'category'       => 'Condition',
                'description'    => 'Matching concept',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                // Reported 'Observation' but central classifies 8507 as 'Gender' → drift.
                'collection_id'  => $this->collection->id,
                'result_file_id' => $resultFileId,
                'concept_id'     => self::CONCEPT_MISMATCH,
                'count'          => 5,
                'name'           => (string) self::CONCEPT_MISMATCH,
                'category'       => 'Observation',
                'description'    => 'Drifted concept',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);

        RefreshLatestDistributionsView::dispatchSync();
    }

    public function test_service_reports_summary_and_mismatch_list(): void
    {
        $report = app(VocabularyDriftService::class)->report($this->collection->id);

        $this->assertEquals(2, $report['total_concepts']);
        $this->assertEquals(1, $report['mismatched_concepts']);
        $this->assertEquals(0.5, $report['mismatch_rate']);

        $this->assertCount(1, $report['mismatches']);
        $mismatch = $report['mismatches'][0];
        $this->assertEquals(self::CONCEPT_MISMATCH, $mismatch['concept_id']);
        $this->assertEquals('Observation', $mismatch['reported_domain']);
        $this->assertEquals('Gender', $mismatch['central_domain']);
    }

    public function test_endpoint_returns_drift_report(): void
    {
        $response = $this->actingAsJwt($this->user)
            ->getJson('/api/v1/collections/' . $this->collection->pid . '/vocab-drift');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.total_concepts'));
        $this->assertEquals(1, $response->json('data.mismatched_concepts'));
        $this->assertEquals(0.5, $response->json('data.mismatch_rate'));
        $this->assertEquals(self::CONCEPT_MISMATCH, $response->json('data.mismatches.0.concept_id'));
    }

    public function test_endpoint_returns_404_for_unknown_collection(): void
    {
        $response = $this->actingAsJwt($this->user)
            ->getJson('/api/v1/collections/does-not-exist/vocab-drift');

        $response->assertNotFound();
    }

    public function test_command_runs_and_reports_drift(): void
    {
        $this->artisan('collections:vocab-drift', ['--details' => true])
            ->assertSuccessful();
    }

    public function test_command_scopes_to_collection_pid(): void
    {
        $this->artisan('collections:vocab-drift', [
            '--collection-pid' => $this->collection->pid,
            '--details' => true,
        ])->assertSuccessful();
    }

    public function test_command_fails_for_unknown_collection_pid(): void
    {
        $this->artisan('collections:vocab-drift', ['--collection-pid' => 'does-not-exist'])
            ->assertFailed();
    }
}
