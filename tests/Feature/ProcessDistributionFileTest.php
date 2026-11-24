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

    public function test_a_code_file_with_no_rows_is_marked_failed(): void
    {
        $file = $this->storeCodeFile([self::CODE_HEADER]);

        (new ProcessDistributionFile($file->id))->handle();

        $file->refresh();
        $this->assertSame(ResultFile::STATUS_FAILED, $file->status);
        $this->assertSame('No concepts found in file', $file->error);
        Queue::assertNotPushed(RefreshDistributionConceptsView::class);
        Queue::assertNotPushed(RefreshLatestDistributionsView::class);
    }

    public function test_a_code_file_with_concepts_is_marked_done(): void
    {
        $file = $this->storeCodeFile([
            self::CODE_HEADER,
            "Condition\t201826\tType 2 diabetes\t10\t\t\t\t\t\t\t",
        ]);

        (new ProcessDistributionFile($file->id))->handle();

        $file->refresh();
        $this->assertSame(ResultFile::STATUS_DONE, $file->status);
        $this->assertSame(1, $file->rows_processed);
        Queue::assertPushed(RefreshDistributionConceptsView::class);
    }

    private function storeCodeFile(array $lines): ResultFile
    {
        $collection = Collection::factory()->create(['custodian_id' => Custodian::factory()->create()->id]);
        $task = Task::factory()->create(['collection_id' => $collection->id]);

        $path = Str::uuid().'-code.distribution';
        Storage::put($path, implode("\n", $lines)."\n");

        return ResultFile::create([
            'pid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'collection_id' => $collection->id,
            'path' => $path,
            'file_name' => 'code.distribution',
            'status' => ResultFile::STATUS_QUEUED,
        ]);
    }
}
