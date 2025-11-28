<?php

namespace Tests\Feature\Api\V1;

use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\Custodian;
use App\Models\Query;
use App\Models\Result;
use App\Models\Task;
use App\Services\QueryContext\QueryContextManager;
use App\Services\QueryContext\QueryContextType;
use Config;
use Illuminate\Support\Facades\Route;
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
    public function it_returns_not_found_for_invalid_collection_pid_in_next_job()
    {
        $response = $this->getJson(self::BASE_URL.'/nextjob/invalid-id');

        $response->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_no_content_when_no_pending_task()
    {
        $collection = Collection::factory()->bunny()->create();

        $response = $this->getJson(self::BASE_URL."/nextjob/{$collection->pid}");

        $response->assertNoContent();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_translated_query_for_next_job()
    {
        $collection = Collection::factory()->bunny()->create();
        $query = Query::factory()->create();
        Task::factory()->create(
            [
                'collection_id' => $collection->id,
                'query_id' => $query->id,
                'task_type' => 'a',
            ]
        );

        $mock = $this->createMock(QueryContextManager::class);
        $mock->expects($this->once())
            ->method('handle')
            ->with($query->definition, $collection->type)
            ->willReturn(['translated' => 'query']);

        $this->app->instance(QueryContextManager::class, $mock);

        $response = $this->getJson(self::BASE_URL."/nextjob/{$collection->pid}");

        $response->assertOk()
            ->assertJsonStructure([
                'task_id',
                'uuid',
                'cohort',
                'project',
                'owner',
                'collection',
                'protocol_version',
                'char_salt',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_bad_request_if_context_manager_fails()
    {
        $collection = Collection::factory()->bunny()->create();
        $query = Query::factory()->create(['definition' => ['some' => 'query']]);
        Task::factory()->create(['collection_id' => $collection->id, 'query_id' => $query->id]);

        $mock = $this->createMock(QueryContextManager::class);
        $mock->expects($this->once())
            ->method('handle')
            ->willThrowException(new \ValueError('Unsupported type'));

        $this->app->instance(QueryContextManager::class, $mock);

        $response = $this->getJson(self::BASE_URL."/nextjob/{$collection->pid}");

        $response->assertStatus(400)
            ->assertJson([
                'data' => 'Unsupported collection type',
                'message' => 'bad request',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_receives_and_stores_query_result()
    {
        $collection = Collection::factory()->create([
            'type' => QueryContextType::Bunny,
        ]);
        $task = Task::factory()->create(['collection_id' => $collection->id]);

        $payload = ['queryResult' => ['count' => 42]];

        $response = $this->postJson(
            self::BASE_URL."/result/{$task->pid}/{$collection->pid}",
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
            'count' => 42,
        ]);

        $this->assertNotNull($task->fresh()->completed_at);

        $collection = Collection::factory()->create([
            'type' => QueryContextType::Beacon,
        ]);
        $task = Task::factory()->create(['collection_id' => $collection->id]);

        $payload = ['queryResult' => ['count' => 42]];

        $response = $this->postJson(
            self::BASE_URL."/result/{$task->pid}/{$collection->pid}",
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
            'count' => 42,
        ]);

        $this->assertNotNull($task->fresh()->completed_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_invalid_result_submission()
    {
        $task = Task::factory()->create();
        $collection = Collection::find($task->collection_id);

        $response = $this->postJson(
            self::BASE_URL."/result/{$task->pid}/{$collection->pid}",
            ['queryResult' => ['foo' => 'bar']]
        );

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'bad request',
                'data' => 'Invalid or missing count in queryResult.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_calls_when_a_client_fails_to_provide_basic_auth(): void
    {
        Config::set('system.basic_auth_enabled', true);
        $this->enableMiddleware();

        $route = Route::getRoutes()->getByName('task.nextjob');
        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();
        $this->assertContains('App\Http\Middleware\CollectionHostBasicAuth', $middleware);
        $this->assertContains('throttle:polling', $middleware);

        $task = Task::factory()->create();
        $collection = Collection::find($task->collection_id);

        $response = $this->get(self::BASE_URL.'/nextjob/'.$collection->pid);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_calls_when_a_client_provides_basic_auth(): void
    {
        Config::set('system.basic_auth_enabled', true);
        $this->enableMiddleware();

        $task = Task::factory()->create();
        $collection = Collection::find($task->collection_id);
        $collectionHost = CollectionHost::factory()->create([
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'custodian_id' => Custodian::factory()->create()->id,
        ]);

        $this->assertNotNull($collectionHost);

        $response = $this->get(self::BASE_URL.'/nextjob/'.$collection->pid, [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode("{$collectionHost->client_id}:{$collectionHost->client_secret}"),
        ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('collection_activity_logs', [
            'collection_id' => $collection->id,
            'task_type' => $task->task_type,
        ]);

        // muddle the keys to ensure the middleware is working with invalid credentials too
        $response = $this->get(self::BASE_URL.'/nextjob/'.$collection->pid, [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode("{$collectionHost->client_id}:wrong-secret"),
        ]);

        $response->assertStatus(401);
    }
}
