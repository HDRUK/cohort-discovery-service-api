<?php

namespace Tests\Feature;

use App\Enums\MissingDataTable;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Result;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\Submitters\TaskFailureRecorder;
use Carbon\Carbon;
use Tests\TestCase;

class TaskFailureRecorderTest extends TestCase
{
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();
        $this->enableObservers();
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('admin');
    }

    public function test_it_fails_a_task_with_a_reason_like_a_worker_error(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

        $task = $this->makeTask();

        app(TaskFailureRecorder::class)->failWithReasons(
            $task,
            [MissingDataTable::Death->reason()]
        );

        $task->refresh();
        $run = TaskRun::where('task_id', $task->id)->where('attempt', 1)->first();
        $result = Result::where('task_id', $task->id)->first();

        $this->assertNotNull($task->failed_at);
        $this->assertNotNull($task->completed_at);

        $this->assertNotNull($run);
        $this->assertSame('failed', $run->result_status);
        $this->assertSame('MissingRequiredTable', $run->error_class);
        $this->assertSame('Death-record data not yet available', $run->error_message);
        $this->assertNotNull($run->finished_at);

        $this->assertNotNull($result);
        $this->assertSame('failed', $result->status);
        $this->assertSame('Death-record data not yet available', $result->message);
        $this->assertSame(0, (int) $result->count);

        Carbon::setTestNow();
    }

    public function test_it_joins_multiple_reasons_into_one_message(): void
    {
        $task = $this->makeTask();

        app(TaskFailureRecorder::class)->failWithReasons($task, [
            MissingDataTable::Location->reason(),
            MissingDataTable::Death->reason(),
        ]);

        $result = Result::where('task_id', $task->id)->first();

        $this->assertSame(
            'Location data not yet available; Death-record data not yet available',
            $result->message
        );
    }

    public function test_failure_reason_surfaces_on_query_show(): void
    {
        $task = $this->makeTask();

        app(TaskFailureRecorder::class)->failWithReasons(
            $task,
            [MissingDataTable::Location->reason()]
        );

        $response = $this->actingAsJwt($this->adminUser, [])
            ->getJson('/api/v1/query/' . $task->submittedQuery->pid);

        $response->assertOk();
        $response->assertJsonPath('data.tasks.0.result.message', 'Location data not yet available');
        $response->assertJsonPath('data.tasks.0.latest_run.error_message', 'Location data not yet available');
    }

    private function makeTask(): Task
    {
        $collection = Collection::factory()->bunny()->create();
        $query = Query::factory()->create(['user_id' => $this->adminUser->id]);

        return Task::factory()->create([
            'collection_id' => $collection->id,
            'query_id' => $query->id,
            'completed_at' => null,
            'failed_at' => null,
        ]);
    }
}
