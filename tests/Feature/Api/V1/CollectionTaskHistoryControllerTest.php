<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\CustodianHasUser;
use App\Models\Query;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionTaskHistoryControllerTest extends TestCase
{
    private User $adminUser;

    private Collection $collection;

    private Query $query;

    protected function setUp(): void
    {
        parent::setUp();

        // decode.jwt must run so the policy has a user to authorise.
        $this->enableMiddleware();

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('admin');

        // Nothing is truncated here - there is no per-test transaction (see
        // RefreshDatabaseLite) and the seeders own the tasks table. A fresh
        // collection cannot have seeded tasks, so scoping by it is enough.
        $this->collection = Collection::factory()->bunny()->create();
        $this->query = Query::factory()->create();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(array $query = []): string
    {
        $url = "/api/v1/collections/{$this->collection->pid}/task-history";

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs
     */
    private function task(array $attributes = [], array $runs = []): Task
    {
        $task = Task::factory()->create(array_merge([
            'collection_id' => $this->collection->id,
            'query_id' => $this->query->id,
            'task_type' => TaskType::A->value,
            'created_at' => Carbon::now(),
            'attempts' => count($runs),
        ], $attributes));

        foreach ($runs as $index => $run) {
            TaskRun::create(array_merge([
                'task_id' => $task->id,
                'attempt' => $index + 1,
                'worker_id' => '10.0.0.1',
                'claimed_at' => $task->created_at,
                'started_at' => $task->created_at,
            ], $run));
        }

        return $task;
    }

    /** A task that ran once, cleanly, in $durationMs. */
    private function succeededTask(int $durationMs, array $attributes = []): Task
    {
        $createdAt = $attributes['created_at'] ?? Carbon::now();

        return $this->task(array_merge([
            'attempted_at' => $createdAt,
            'completed_at' => Carbon::parse($createdAt)->addMilliseconds($durationMs),
        ], $attributes), [
            [
                'finished_at' => Carbon::parse($createdAt)->addMilliseconds($durationMs),
                'duration_ms' => $durationMs,
                'result_status' => 'ok',
            ],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_a_task_with_its_runs_and_defaults_to_the_last_day()
    {
        $task = $this->succeededTask(1900);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame($this->collection->id, $data['collection_id']);
        // The default window is one day back from now.
        $this->assertSame(
            Carbon::parse($data['to'])->subDay()->toIso8601ZuluString(),
            $data['from']
        );

        $this->assertSame(1, $data['tasks']['total']);

        $item = $data['tasks']['data'][0];
        $this->assertSame((string) $task->pid, $item['pid']);
        $this->assertSame('a', $item['task_type']);
        $this->assertSame('succeeded', $item['status']);
        $this->assertSame(1, $item['attempts']);
        $this->assertSame(1900, $item['duration_ms']);
        $this->assertSame(1900, $item['total_duration_ms']);
        $this->assertSame((string) $this->query->pid, $item['query']['pid']);

        $this->assertCount(1, $item['runs']);
        $this->assertSame(1, $item['runs'][0]['attempt']);
        $this->assertSame('10.0.0.1', $item['runs'][0]['worker_id']);
        $this->assertSame(1900, $item['runs'][0]['duration_ms']);
        $this->assertSame('ok', $item['runs'][0]['result_status']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_excludes_tasks_outside_the_window()
    {
        $inside = $this->succeededTask(500);
        $this->succeededTask(500, ['created_at' => Carbon::now()->subDays(3)]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();
        $this->assertSame(1, $response->json('data.tasks.total'));
        $this->assertSame((string) $inside->pid, $response->json('data.tasks.data.0.pid'));

        // A wider window reaches the older one.
        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url(['window' => '7d']));

        $response->assertOk();
        $this->assertSame(2, $response->json('data.tasks.total'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_honours_an_explicit_inclusive_range()
    {
        $createdAt = Carbon::now()->subDays(5)->startOfSecond();
        $task = $this->succeededTask(400, ['created_at' => $createdAt]);

        // Both ends land exactly on the task, which an inclusive range must include.
        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url([
            'from' => $createdAt->toIso8601ZuluString(),
            'to' => $createdAt->toIso8601ZuluString(),
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->json('data.tasks.total'));
        $this->assertSame((string) $task->pid, $response->json('data.tasks.data.0.pid'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prefers_an_explicit_from_over_the_window()
    {
        $this->succeededTask(400, ['created_at' => Carbon::now()->subDays(3)]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url([
            'window' => '1h',
            'from' => Carbon::now()->subWeek()->toIso8601ZuluString(),
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->json('data.tasks.total'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_lists_newest_first()
    {
        $older = $this->succeededTask(100, ['created_at' => Carbon::now()->subHours(3)]);
        $newer = $this->succeededTask(100, ['created_at' => Carbon::now()->subHour()]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();
        $pids = array_column($response->json('data.tasks.data'), 'pid');
        $this->assertSame([(string) $newer->pid, (string) $older->pid], $pids);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_reports_every_attempt_and_the_time_each_took()
    {
        $createdAt = Carbon::now()->subMinutes(10);

        $task = $this->task([
            'created_at' => $createdAt,
            'attempted_at' => $createdAt->copy()->addSeconds(4),
            'completed_at' => $createdAt->copy()->addSeconds(9),
            'attempts' => 2,
        ], [
            [
                'claimed_at' => $createdAt->copy()->addSeconds(4),
                'started_at' => $createdAt->copy()->addSeconds(4),
                'finished_at' => $createdAt->copy()->addSeconds(5),
                'duration_ms' => 1000,
                'result_status' => 'failed',
                'error_class' => 'WorkerResultError',
                'error_message' => 'datasource unreachable',
            ],
            [
                'claimed_at' => $createdAt->copy()->addSeconds(7),
                'started_at' => $createdAt->copy()->addSeconds(7),
                'finished_at' => $createdAt->copy()->addSeconds(9),
                'duration_ms' => 2000,
                'result_status' => 'ok',
            ],
        ]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();
        $item = $response->json('data.tasks.data.0');

        $this->assertSame((string) $task->pid, $item['pid']);
        $this->assertSame(2, $item['attempts']);
        // Runs are oldest first, so the shape reads as a retry history.
        $this->assertCount(2, $item['runs']);
        $this->assertSame([1, 2], array_column($item['runs'], 'attempt'));
        $this->assertSame('failed', $item['runs'][0]['result_status']);
        $this->assertSame('WorkerResultError', $item['runs'][0]['error_class']);
        $this->assertSame('datasource unreachable', $item['runs'][0]['error_message']);

        // The settling attempt, not the first one.
        $this->assertSame(2000, $item['duration_ms']);
        // Both attempts, so a retried task is not reported as cheap as its last run.
        $this->assertSame(3000, $item['total_duration_ms']);
        // Created until first claimed.
        $this->assertSame(4000, $item['queued_for_ms']);

        $attempts = $response->json('data.summary.attempts');
        $this->assertSame(2, $attempts['total']);
        $this->assertSame(1, $attempts['retried_tasks']);
        $this->assertSame(2, $attempts['max']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_derives_each_task_status_from_the_task_timestamps()
    {
        $now = Carbon::now();

        $this->succeededTask(100);

        // A failure sets completed_at as well as failed_at, so failed has to win.
        $this->task([
            'attempted_at' => $now,
            'completed_at' => $now,
            'failed_at' => $now,
            'attempts' => 1,
        ]);

        // Claimed, no result yet.
        $this->task(['attempted_at' => $now, 'attempts' => 1]);

        // Queued, never claimed.
        $this->task();

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url(['per_page' => 100]));

        $response->assertOk();
        $summary = $response->json('data.summary');

        $this->assertSame(4, $summary['tasks']);
        $this->assertSame(1, $summary['succeeded']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['in_flight']);
        $this->assertSame(1, $summary['pending']);

        $statuses = array_column($response->json('data.tasks.data'), 'status');
        sort($statuses);
        $this->assertSame(['failed', 'in_flight', 'pending', 'succeeded'], $statuses);
    }

    /**
     * TaskCleanupJob records a timed-out attempt with an error_class but no
     * result_status and no duration, so neither can be assumed present.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_a_run_that_never_reported_a_status_or_duration()
    {
        $now = Carbon::now();

        $this->task([
            'attempted_at' => $now,
            'completed_at' => $now,
            'failed_at' => $now,
            'attempts' => 1,
        ], [
            [
                'finished_at' => $now,
                'error_class' => 'Timeout',
                'error_message' => 'No result received within 300 seconds.',
            ],
        ]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();
        $item = $response->json('data.tasks.data.0');

        $this->assertSame('failed', $item['status']);
        $this->assertNull($item['duration_ms']);
        $this->assertNull($item['total_duration_ms']);
        $this->assertNull($item['runs'][0]['result_status']);
        $this->assertSame('Timeout', $item['runs'][0]['error_class']);

        // The attempt happened, but there is no duration to describe.
        $this->assertSame(1, $response->json('data.summary.attempts.total'));
        $this->assertSame(0, $response->json('data.summary.duration_ms.runs_measured'));
        $this->assertNull($response->json('data.summary.duration_ms.p95'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_summarises_the_run_duration_distribution()
    {
        foreach ([100, 200, 300, 400, 5000] as $durationMs) {
            $this->succeededTask($durationMs);
        }

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();
        $durations = $response->json('data.summary.duration_ms');

        $this->assertSame(5, $durations['runs_measured']);
        $this->assertSame(100, $durations['min']);
        $this->assertSame(5000, $durations['max']);
        $this->assertSame(1200, $durations['avg']);
        // Nearest-rank: the smallest duration whose cumulative share reaches p.
        $this->assertSame(300, $durations['p50']);
        // The outlier the mean barely notices is exactly what p95 is for.
        $this->assertSame(5000, $durations['p95']);
    }

    /**
     * The summary describes the range, so it must not shrink to the page - that is
     * the whole reason it is computed in SQL rather than from the loaded models.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_summarises_the_whole_range_not_just_the_page()
    {
        foreach (range(1, 5) as $step) {
            $this->succeededTask($step * 100);
        }

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url(['per_page' => 2]));

        $response->assertOk();

        $this->assertCount(2, $response->json('data.tasks.data'));
        $this->assertSame(5, $response->json('data.tasks.total'));
        $this->assertSame(2, $response->json('data.tasks.per_page'));

        $this->assertSame(5, $response->json('data.summary.tasks'));
        $this->assertSame(5, $response->json('data.summary.duration_ms.runs_measured'));
        $this->assertSame(500, $response->json('data.summary.duration_ms.max'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_filters_the_page_and_the_summary_together_by_status()
    {
        $now = Carbon::now();

        $this->succeededTask(100);
        $failed = $this->task([
            'attempted_at' => $now,
            'completed_at' => $now,
            'failed_at' => $now,
            'attempts' => 1,
        ], [
            ['finished_at' => $now, 'duration_ms' => 900, 'result_status' => 'failed'],
        ]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url(['status' => 'failed']));

        $response->assertOk();

        $this->assertSame(1, $response->json('data.tasks.total'));
        $this->assertSame((string) $failed->pid, $response->json('data.tasks.data.0.pid'));

        // The summary narrows with it, rather than describing the unfiltered range.
        $this->assertSame(1, $response->json('data.summary.tasks'));
        $this->assertSame(1, $response->json('data.summary.failed'));
        $this->assertSame(0, $response->json('data.summary.succeeded'));
        $this->assertSame(900, $response->json('data.summary.duration_ms.max'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_filters_the_page_and_the_summary_together_by_task_type()
    {
        $this->succeededTask(100);
        $distribution = $this->succeededTask(2000, ['task_type' => TaskType::B->value]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url(['task_type' => 'b']));

        $response->assertOk();

        $this->assertSame(1, $response->json('data.tasks.total'));
        $this->assertSame((string) $distribution->pid, $response->json('data.tasks.data.0.pid'));
        $this->assertSame(1, $response->json('data.summary.tasks'));
        $this->assertSame(2000, $response->json('data.summary.duration_ms.max'));
        $this->assertSame(['a' => 0, 'b' => 1], $response->json('data.summary.task_types'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_breaks_the_task_count_down_by_task_type()
    {
        $this->succeededTask(100);
        $this->succeededTask(100);
        $this->succeededTask(100, ['task_type' => TaskType::B->value]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();
        $this->assertSame(['a' => 2, 'b' => 1], $response->json('data.summary.task_types'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_ignores_tasks_belonging_to_another_collection()
    {
        $other = Collection::factory()->bunny()->create();

        $this->succeededTask(100);
        $this->succeededTask(100, ['collection_id' => $other->id]);

        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();
        $this->assertSame(1, $response->json('data.tasks.total'));
        $this->assertSame(1, $response->json('data.summary.tasks'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_an_empty_history_for_a_collection_that_has_run_nothing()
    {
        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url());

        $response->assertOk();

        $this->assertSame([], $response->json('data.tasks.data'));
        $this->assertSame(0, $response->json('data.summary.tasks'));
        $this->assertSame(0, $response->json('data.summary.attempts.total'));
        $this->assertSame(0, $response->json('data.summary.duration_ms.runs_measured'));
        $this->assertNull($response->json('data.summary.duration_ms.avg'));
        $this->assertNull($response->json('data.summary.duration_ms.p50'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_a_collection_by_numeric_id()
    {
        $response = $this->actingAsJwt($this->adminUser)
            ->getJson("/api/v1/collections/{$this->collection->id}/task-history");

        $response->assertOk();
        $this->assertSame($this->collection->id, $response->json('data.collection_id'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_caps_per_page()
    {
        $response = $this->actingAsJwt($this->adminUser)->getJson($this->url(['per_page' => 5000]));

        $response->assertOk();
        $this->assertSame(100, $response->json('data.tasks.per_page'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_a_malformed_window()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url(['window' => 'soon']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('window');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_a_from_later_than_to()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url([
                'from' => Carbon::now()->toIso8601ZuluString(),
                'to' => Carbon::now()->subDay()->toIso8601ZuluString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_an_unknown_status_or_task_type()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url(['status' => 'exploded']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAsJwt($this->adminUser)
            ->getJson($this->url(['task_type' => 'z']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('task_type');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_not_found_for_an_unknown_collection()
    {
        $this->actingAsJwt($this->adminUser)
            ->getJson('/api/v1/collections/'.Str::uuid().'/task-history')
            ->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_forbids_a_user_unconnected_to_the_collections_custodian()
    {
        $outsider = User::factory()->create();

        $this->actingAsJwt($outsider)
            ->getJson($this->url())
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_a_user_attached_to_the_collections_custodian()
    {
        $custodianUser = User::factory()->create();
        CustodianHasUser::create([
            'custodian_id' => $this->collection->custodian_id,
            'user_id' => $custodianUser->id,
        ]);

        $this->actingAsJwt($custodianUser)
            ->getJson($this->url())
            ->assertOk();
    }
}
