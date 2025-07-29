<?php

namespace Tests\Feature\Api\V1;

use App\Models\Collection;
use App\Models\Query;
use App\Models\Result;
use App\Models\Task;
use App\Services\QueryContext\QueryContextManager;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    private const BASE_URL = '/api/v1/task';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableObservers();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_input_when_submitting_query()
    {
        $response = $this->postJson(self::BASE_URL, []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'definition']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_query_and_tasks_correctly()
    {
        $collections = Collection::factory()->bunny()->count(3)->create();

        $payload = [
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
        ];

        $response = $this->postJson(self::BASE_URL, $payload);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'query_pid',
                    'task_count',
                    'task_pids',
                ],
            ]);


        $this->assertDatabaseCount(Task::class, 3);
        $this->assertDatabaseHas(Query::class, ['name' => 'Test Query']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_not_found_for_invalid_collection_pid_in_next_job()
    {
        $response = $this->getJson(self::BASE_URL . '/nextjob/invalid-id');

        $response->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_no_content_when_no_pending_task()
    {
        $collection = Collection::factory()->bunny()->create();

        $response = $this->getJson(self::BASE_URL . "/nextjob/{$collection->pid}");

        $response->assertNoContent();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_translated_query_for_next_job()
    {
        $collection = Collection::factory()->bunny()->create();
        $query = Query::factory()->create();
        $task = Task::factory()->create(
            [
                'collection_id' => $collection->id,
                'query_id' => $query->id
            ]
        );

        $mock = $this->createMock(QueryContextManager::class);
        $mock->expects($this->once())
            ->method('handle')
            ->with($query->definition, $collection->type)
            ->willReturn(['translated' => 'query']);

        $this->app->instance(QueryContextManager::class, $mock);

        $response = $this->getJson(self::BASE_URL . "/nextjob/{$collection->pid}");

        $response->assertOk()
            ->assertJsonStructure([
                'task_id',
                'uuid',
                'cohort',
                'project',
                'owner',
                'collection',
                'protocol_version',
                'char_salt'
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_bad_request_if_context_manager_fails()
    {
        $collection = Collection::factory()->bunny()->create();
        $query = Query::factory()->create(['definition' => ['some' => 'query']]);
        $task = Task::factory()->create(['collection_id' => $collection->id, 'query_id' => $query->id]);

        $mock = $this->createMock(QueryContextManager::class);
        $mock->expects($this->once())
            ->method('handle')
            ->willThrowException(new \ValueError('Unsupported type'));

        $this->app->instance(QueryContextManager::class, $mock);

        $response = $this->getJson(self::BASE_URL . "/nextjob/{$collection->pid}");

        $response->assertStatus(400)
            ->assertJson([
                'data' => 'Unsupported collection type',
                'message' => 'bad request',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_receives_and_stores_query_result()
    {
        $task = Task::factory()->create();
        $collection = Collection::find($task->collection_id);

        $payload = ['queryResult' => ['count' => 42]];

        $response = $this->postJson(
            self::BASE_URL . "/result/{$task->pid}/{$collection->pid}",
            $payload
        );

        $response->assertCreated()
            ->assertJson([
                'message' => 'success',
                'data' => [
                    'message' => 'Result received successfully.',
                ],
            ]);

        $this->assertDatabaseHas(Result::class, [
            'task_id' => $task->id,
            'count' => 42
        ]);

        $this->assertNotNull($task->fresh()->completed_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_invalid_result_submission()
    {
        $task = Task::factory()->create();
        $collection = Collection::find($task->collection_id);

        $response = $this->postJson(
            self::BASE_URL . "/result/{$task->pid}/{$collection->pid}",
            ['queryResult' => ['foo' => 'bar']]
        );

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'bad request',
                'data' => 'Invalid or missing count in queryResult.',
            ]);
    }
}
