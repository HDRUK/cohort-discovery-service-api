<?php

namespace Tests\Feature\Api\V1;

use DB;
use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use Tests\TestCase;

class QueryControllerTest extends TestCase
{
    private const BASE_URL = '/api/v1/queries';
    private const QUERY_URL = '/api/v1/query';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Task::truncate();
        Query::truncate();
        Collection::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->enableObservers();

        $this->user = User::factory()->create();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_search_queries(): void
    {
        $this->enableMiddleware();
        $collections = Collection::factory(3)->create();

        $payload = [
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A
        ];

        $response = $this->actingAsJwt($this->user)
            ->postJson(self::BASE_URL, $payload);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'query_pid',
                    'task_count',
                    'task_pids',
                ],
            ]);

        $this->assertDatabaseHas(Query::class, ['name' => 'Test Query']);
        
        $response = $this->getJson(self::BASE_URL . '?name[]=Test%20Query');
        $response->assertStatus(200);

        $content = $response->json('data');

        $this->assertTrue(count($content['data']) === 1);
        $this->assertTrue($content['data'][0]['name'] === $payload['name']);

        foreach ($content['data'][0]['tasks'] as $t) {
            $this->assertEquals($t['query_id'], $content['data'][0]['id']);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_input_when_submitting_query(): void
    {
        $response = $this->postJson(self::BASE_URL, []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'definition']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_query_and_tasks_correctly(): void
    {
        $this->disableObservers();
        $this->enableMiddleware();

        $n = 3;
        $collections = Collection::factory()->bunny()->count($n)->create();

        $payload = [
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A
        ];

        $response = $this->actingAsJwt($this->user)
            ->postJson(self::BASE_URL, $payload);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'query_pid',
                    'task_count',
                    'task_pids',
                ],
            ]);


        $this->assertDatabaseCount(Task::class, $n);
        $this->assertDatabaseHas(Query::class, ['name' => 'Test Query']);

        $this->enableObservers();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_only_creates_tasks_for_filtered_collections(): void
    {
        $this->disableObservers();
        $this->enableMiddleware();

        $included = Collection::factory()->bunny()->create();
        Collection::factory()->bunny()->count(2)->create();

        $payload = [
            'name' => 'Filtered Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => [$included->pid],
            'task_type' => 'a'
        ];

        $response = $this->actingAsJwt($this->user)
            ->postJson(self::BASE_URL, $payload);

        $response->assertCreated()
            ->assertJsonPath('data.task_count', 1)
            ->assertJsonCount(1, 'data.task_pids');

        $this->assertDatabaseCount(Task::class, 1);
        $this->assertDatabaseHas(Query::class, ['name' => 'Filtered Query']);

        $this->enableObservers();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_and_views_with_correct_auth(): void
    {
        $this->disableObservers();
        $this->enableMiddleware();

        $altUser = User::factory()->create();

        $n = 3;
        $collections = Collection::factory()->bunny()->count($n)->create();

        $payload = [
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A
        ];

        $response = $this->actingAsJwt($this->user)
            ->postJson(self::BASE_URL, $payload);

        $response->assertCreated();

        $pid = $response->decodeResponseJson()['data']['query_pid'];

        $response = $this->actingAsJwt($this->user)
            ->get(self::QUERY_URL . "/" . $pid);

        $response->assertSuccessful();

        $response = $this->actingAsJwt($altUser)
            ->get(self::QUERY_URL . "/" . $pid);
        $response->assertForbidden();

        $response = $this->actingAsJwt($this->user)
            ->get(self::BASE_URL);
        $response->assertSuccessful();

        $this->assertEquals(1, count($response->json('data.data')));

        $response = $this->actingAsJwt($altUser)
            ->get(self::BASE_URL);
        $response->assertSuccessful();

        $this->assertEquals(0, count($response->json('data.data')));
    }
}
