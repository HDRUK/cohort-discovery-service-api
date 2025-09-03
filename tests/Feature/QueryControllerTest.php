<?php

namespace Tests\Feature\Api\V1;

use Config;

use App\Enums\TaskType;
use App\Models\Custodian;
use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\Query;
use App\Models\Result;
use App\Models\Task;
use App\Services\QueryContext\QueryContextManager;

use Illuminate\Support\Facades\Route;

use Tests\TestCase;

class QueryControllerTest extends TestCase
{
    private const BASE_URL = '/api/v1/queries';

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
        $n = 3;
        $collections = Collection::factory()->bunny()->count($n)->create();

        Task::truncate();

        $payload = [
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A
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


        $this->assertDatabaseCount(Task::class, $n);
        $this->assertDatabaseHas(Query::class, ['name' => 'Test Query']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_only_creates_tasks_for_filtered_collections()
    {
        $included = Collection::factory()->bunny()->create();
        Collection::factory()->bunny()->count(2)->create();

        Task::truncate();

        $payload = [
            'name' => 'Filtered Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => [$included->pid],
            'task_type' => 'a'
        ];

        $response = $this->postJson(self::BASE_URL, $payload);

        $response->assertCreated()
            ->assertJsonPath('data.task_count', 1)
            ->assertJsonCount(1, 'data.task_pids');

        $this->assertDatabaseCount(Task::class, 1);
        $this->assertDatabaseHas(Query::class, ['name' => 'Filtered Query']);
    }
}
