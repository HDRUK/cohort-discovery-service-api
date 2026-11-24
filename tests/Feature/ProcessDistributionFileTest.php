<?php

namespace Tests\Feature;

use App\Jobs\ProcessDistributionFile;
use App\Jobs\RefreshDistributionConceptsView;
use App\Jobs\RefreshLatestDistributionsView;
use App\Models\Collection;
use App\Models\Custodian;
use App\Models\ResultFile;
use App\Models\Task;
use DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Str;
use Tests\TestCase;

class ProcessDistributionFileTest extends TestCase
{
    private const CODE_HEADER = "CATEGORY\tOMOP\tOMOP_DESCR\tCOUNT\tQ1\tQ3\tMIN\tMAX\tMEAN\tMEDIAN\tALTERNATIVES";

    private const DEMOGRAPHICS_HEADER = "CATEGORY\tCODE\tDESCRIPTION\tCOUNT\tQ1\tQ3\tMIN\tMAX\tMEAN\tMEDIAN\tALTERNATIVES";

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Collection::truncate();
        Task::truncate();
        DB::table('result_files')->truncate();
        DB::table('distributions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        Storage::fake();
        Queue::fake();
    }

    public function test_a_code_file_with_only_a_header_is_marked_failed(): void
    {
        $file = $this->storeFile('code.distribution', [self::CODE_HEADER]);

        (new ProcessDistributionFile($file->id))->handle();

        $file->refresh();
        $this->assertSame(ResultFile::STATUS_FAILED, $file->status);
        $this->assertSame('No concepts found in file', $file->error);
        $this->assertSame(0, $file->rows_processed);
    }

    public function test_a_code_file_whose_codes_are_not_concept_ids_is_marked_failed(): void
    {
        $file = $this->storeFile('code.distribution', [
            self::CODE_HEADER,
            "Condition\tnot-a-concept\tUnmapped\t10\t\t\t\t\t\t\t",
        ]);

        (new ProcessDistributionFile($file->id))->handle();

        $file->refresh();
        $this->assertSame(ResultFile::STATUS_FAILED, $file->status);
        $this->assertSame(1, $file->rows_processed);
    }

    public function test_a_failed_code_file_does_not_refresh_the_views(): void
    {
        $file = $this->storeFile('code.distribution', [self::CODE_HEADER]);

        (new ProcessDistributionFile($file->id))->handle();

        Queue::assertNotPushed(RefreshDistributionConceptsView::class);
        Queue::assertNotPushed(RefreshLatestDistributionsView::class);
    }

    public function test_a_code_file_with_concepts_is_marked_done_and_refreshes_the_views(): void
    {
        $file = $this->storeFile('code.distribution', [
            self::CODE_HEADER,
            "Condition\t201826\tType 2 diabetes\t10\t\t\t\t\t\t\t",
        ]);

        (new ProcessDistributionFile($file->id))->handle();

        $file->refresh();
        $this->assertSame(ResultFile::STATUS_DONE, $file->status);
        $this->assertNull($file->error);
        $this->assertSame(1, $file->rows_processed);
        $this->assertDatabaseHas('distributions', ['result_file_id' => $file->id, 'concept_id' => 201826]);
        Queue::assertPushed(RefreshDistributionConceptsView::class);
        Queue::assertPushed(RefreshLatestDistributionsView::class);
    }

    public function test_a_demographics_file_without_concept_ids_is_still_done(): void
    {
        $file = $this->storeFile('demographics.distribution', [
            self::DEMOGRAPHICS_HEADER,
            "DEMOGRAPHICS\tFEMALE\tFemale\t10\t\t\t\t\t\t\t",
        ]);

        (new ProcessDistributionFile($file->id))->handle();

        $this->assertSame(ResultFile::STATUS_DONE, $file->refresh()->status);
    }

    private function storeFile(string $fileName, array $lines): ResultFile
    {
        $collection = Collection::factory()->create(['custodian_id' => Custodian::factory()->create()->id]);
        $task = Task::factory()->create(['collection_id' => $collection->id]);

        $path = Str::uuid().'-'.$fileName;
        Storage::put($path, implode("\n", $lines)."\n");

        return ResultFile::create([
            'pid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'collection_id' => $collection->id,
            'path' => $path,
            'file_name' => $fileName,
            'status' => ResultFile::STATUS_QUEUED,
        ]);
    }
}
