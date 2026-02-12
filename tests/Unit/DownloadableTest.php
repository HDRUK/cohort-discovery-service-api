<?php

namespace Tests\Unit;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Query;
use App\Models\User;
use League\Csv\Reader;
use Str;
use Tests\TestCase;

class DownloadableTest extends TestCase
{
    private User $user;

    private string $baseUrl = '/api/v1/queries/{pid}/download';

    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->urls = [
            $this->baseUrl.'/json',
            $this->baseUrl.'/csv',
        ];

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_it_can_export_queries_to_json(): void
    {
        $this->enableMiddleware();
        $this->enableObservers();

        $altUser = User::factory()->create();

        $collections = Collection::factory(3)->create();

        $query = Query::create([
            'pid' => Str::uuid(),
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(str_replace('{pid}', $query->pid, $this->urls[0]));

        $response->assertStatus(200);
        $data = json_decode($response->streamedContent(), true);

        $this->assertTrue(count($data) === 1);
        $this->assertEquals($data[0]['id'], $query->id);
        $this->assertEquals($data[0]['pid'], $query->pid);
        $this->assertEquals($data[0]['name'], $query->name);

        $response = $this->actingAsJwt($altUser)
            ->getJson(str_replace('{pid}', $query->pid, $this->urls[0]));
        $response->assertForbidden();


        $query = Query::create([
            'pid' => Str::uuid(),
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A,
            'user_id' => $altUser->id,
        ]);

        $response = $this->actingAsJwt(
            $altUser,
            []
        )
            ->getJson(str_replace('{pid}', $query->pid, $this->urls[0]));
        $response->assertStatus(200);
    }

    public function test_it_can_export_queries_to_csv(): void
    {
        $this->enableMiddleware();
        $this->enableObservers();

        $altUser = User::factory()->create();

        $collections = Collection::factory(3)->create();

        $query = Query::create([
            'pid' => Str::uuid(),
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(str_replace('{pid}', $query->pid, $this->urls[1]));

        $response->assertStatus(200);

        $this->assertInstanceOf(
            \Symfony\Component\HttpFoundation\BinaryFileResponse::class,
            $response->baseResponse
        );

        $filePath = $response->baseResponse->getFile()->getPathname();

        $csv = Reader::createFromPath($filePath, 'r');
        $records = iterator_to_array($csv->getRecords());

        $this->assertTrue(count($records) === 1);
        $this->assertEquals($records[0][0], $query->id);
        $this->assertEquals($records[0][1], $query->pid);
        $this->assertEquals($records[0][2], $query->name);

        $response = $this->actingAsJwt($altUser)
            ->getJson(str_replace('{pid}', $query->pid, $this->urls[1]));
        $response->assertForbidden();

        $query = Query::create([
            'pid' => Str::uuid(),
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collections->pluck('pid')->toArray(),
            'task_type' => TaskType::A,
            'user_id' => $altUser->id,
        ]);

        $response = $this->actingAsJwt(
            $altUser,
            []
        )
            ->getJson(str_replace('{pid}', $query->pid, $this->urls[1]));
        $response->assertStatus(200);
    }
}
