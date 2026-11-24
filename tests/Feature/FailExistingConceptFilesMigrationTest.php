<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Custodian;
use App\Models\Task;
use DB;
use Str;
use Tests\TestCase;

class FailExistingConceptFilesMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_24_100000_fail_existing_concept_files_without_concepts.php';

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Collection::truncate();
        Task::truncate();
        DB::table('result_files')->truncate();
        DB::table('distributions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->collection = Collection::factory()->create(['custodian_id' => Custodian::factory()->create()->id]);
    }

    public function test_it_fails_a_done_concept_file_with_no_concepts(): void
    {
        $fileId = $this->createFile('code.distribution', 'done');

        $this->runMigration();

        $this->assertDatabaseHas('result_files', [
            'id' => $fileId,
            'status' => 'failed',
            'error' => 'No concepts found in file',
        ]);
    }

    public function test_it_fails_a_done_concept_file_whose_rows_have_no_concept_ids(): void
    {
        $fileId = $this->createFile('code.distribution', 'done');
        $this->createDistribution($fileId, conceptId: null);

        $this->runMigration();

        $this->assertDatabaseHas('result_files', ['id' => $fileId, 'status' => 'failed']);
    }

    public function test_it_keeps_a_done_concept_file_that_has_concepts(): void
    {
        $fileId = $this->createFile('code.distribution', 'done');
        $this->createDistribution($fileId, conceptId: 201826);

        $this->runMigration();

        $this->assertDatabaseHas('result_files', ['id' => $fileId, 'status' => 'done', 'error' => null]);
    }

    public function test_it_keeps_a_file_whose_concepts_predate_result_file_ids(): void
    {
        $fileId = $this->createFile('code.distribution', 'done');
        $this->createDistribution($fileId, conceptId: 201826, linkedToResultFile: false);

        $this->runMigration();

        $this->assertDatabaseHas('result_files', ['id' => $fileId, 'status' => 'done']);
    }

    public function test_it_leaves_other_files_and_statuses_alone(): void
    {
        $demographicsId = $this->createFile('demographics.distribution', 'done');
        $alreadyFailedId = $this->createFile('code.distribution', 'failed');
        $queuedId = $this->createFile('code.distribution', 'queued');

        $this->runMigration();

        $this->assertDatabaseHas('result_files', ['id' => $demographicsId, 'status' => 'done']);
        $this->assertDatabaseHas('result_files', ['id' => $alreadyFailedId, 'status' => 'failed', 'error' => null]);
        $this->assertDatabaseHas('result_files', ['id' => $queuedId, 'status' => 'queued']);
    }

    private function runMigration(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function createFile(string $fileName, string $status): int
    {
        $task = Task::factory()->create(['collection_id' => $this->collection->id]);

        return DB::table('result_files')->insertGetId([
            'pid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'collection_id' => $this->collection->id,
            'path' => 'test/'.Str::uuid(),
            'file_name' => $fileName,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDistribution(int $resultFileId, ?int $conceptId, bool $linkedToResultFile = true): void
    {
        $taskId = DB::table('result_files')->where('id', $resultFileId)->value('task_id');

        DB::table('distributions')->insert([
            'collection_id' => $this->collection->id,
            'task_id' => $taskId,
            'result_file_id' => $linkedToResultFile ? $resultFileId : null,
            'concept_id' => $conceptId,
            'count' => 10,
            'name' => 'CONCEPT',
            'category' => 'Condition',
            'description' => 'Concept',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
