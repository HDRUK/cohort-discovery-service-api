<?php

namespace Tests\Unit;

use DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use App\Services\Submitters\QuerySubmissionService;
use App\Models\Query;
use App\Models\Collection;
use App\Models\Task;
use App\Enums\TaskType;

class QuerySubmissionServiceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Query::truncate();
        Collection::truncate();
        Task::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_it_creates_a_query_and_tasks_for_collections(): void
    {
        Queue::fake();

        // Arrange
        $collections = Collection::factory()->count(2)->create();

        $data = [
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A->value,
        ];

        $service = new QuerySubmissionService(
            new Query(),
            new Collection(),
            new Task(),
        );

        // Run
        $result = $service->handle($data, 1);

        $this->assertArrayHasKey('query_pid', $result);
        $this->assertEquals(2, $result['task_count']);
        $this->assertCount(2, $result['task_pids']);
        $this->assertDatabaseHas('queries', [
            'name' => 'Test Query',
        ]);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_it_handles_empty_collection_filter_gracefully(): void
    {
        $data = [
            'name' => 'Query without filter',
            'definition' => ['some' => 'definition'],
            'collection_filter' => [],
            'task_type' => TaskType::A->value,
        ];

        $service = new QuerySubmissionService(
            new Query(),
            new Collection(),
            new Task(),
        );
        $result = $service->handle($data, 1);

        $this->assertArrayHasKey('query_pid', $result);
        $this->assertEquals(0, $result['task_count']);
    }
}
