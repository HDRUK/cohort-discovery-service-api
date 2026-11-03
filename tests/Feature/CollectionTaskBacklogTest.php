<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use App\Services\Collections\CollectionBacklogLimiter;
use Carbon\Carbon;
use DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class CollectionTaskBacklogTest extends TestCase
{
    private const BASE_URL = '/api/v1/queries';

    private const LIMIT = 2;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Task::truncate();
        Query::truncate();
        Collection::truncate();
        DB::table('task_runs')->truncate();
        DB::table('results')->truncate();
        DB::table('activity_log')->truncate();
        DB::table('features')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('system.collection_pending_task_limit', self::LIMIT);

        $this->user = User::factory()->create();
        $this->user->assignRole('user');
    }

    private function fillBacklog(Collection $collection, int $count, ?Carbon $completedAt = null): void
    {
        $query = Query::factory()->create(['user_id' => $this->user->id]);

        Task::factory()->count($count)->create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
            'completed_at' => $completedAt,
        ]);
    }

    private function submit(array $collectionPids): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsJwt($this->user)->postJson(self::BASE_URL, [
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collectionPids,
            'task_type' => TaskType::A,
        ]);
    }

    private function submittedTaskFor(Collection $collection): Task
    {
        return Task::where('collection_id', $collection->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    public function test_flag_off_still_queues_tasks_for_a_saturated_collection(): void
    {
        Feature::deactivate(CollectionBacklogLimiter::FEATURE);

        $collection = Collection::factory()->bunny()->create();
        $this->fillBacklog($collection, self::LIMIT);

        $this->submit([$collection->pid])->assertCreated();

        $task = $this->submittedTaskFor($collection);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->failed_at);
    }

    public function test_flag_on_fails_the_task_once_the_backlog_is_reached(): void
    {
        Feature::activate(CollectionBacklogLimiter::FEATURE);

        $collection = Collection::factory()->bunny()->create();
        $this->fillBacklog($collection, self::LIMIT);

        $this->submit([$collection->pid])->assertCreated();

        $task = $this->submittedTaskFor($collection);
        $this->assertNotNull($task->completed_at);
        $this->assertNotNull($task->failed_at);

        $this->assertDatabaseHas('results', [
            'task_id' => $task->id,
            'status' => 'failed',
            'message' => CollectionBacklogLimiter::BLOCKED_REASON,
        ]);

        $this->assertDatabaseHas('task_runs', [
            'task_id' => $task->id,
            'error_class' => CollectionBacklogLimiter::ERROR_CLASS,
            'error_message' => CollectionBacklogLimiter::BLOCKED_REASON,
        ]);
    }

    public function test_only_the_saturated_collection_is_blocked(): void
    {
        Feature::activate(CollectionBacklogLimiter::FEATURE);

        $saturated = Collection::factory()->bunny()->create();
        $healthy = Collection::factory()->bunny()->create();
        $this->fillBacklog($saturated, self::LIMIT);

        $this->submit([$saturated->pid, $healthy->pid])->assertCreated();

        $this->assertNotNull($this->submittedTaskFor($saturated)->failed_at);
        $this->assertNull($this->submittedTaskFor($healthy)->failed_at);
    }

    public function test_completed_tasks_do_not_count_towards_the_backlog(): void
    {
        Feature::activate(CollectionBacklogLimiter::FEATURE);

        $collection = Collection::factory()->bunny()->create();
        $this->fillBacklog($collection, self::LIMIT + 3, Carbon::now());

        $this->submit([$collection->pid])->assertCreated();

        $this->assertNull($this->submittedTaskFor($collection)->failed_at);
    }

    public function test_pending_tasks_of_any_type_count_towards_the_backlog(): void
    {
        Feature::activate(CollectionBacklogLimiter::FEATURE);

        $collection = Collection::factory()->bunny()->create();
        $query = Query::factory()->create(['user_id' => $this->user->id]);

        Task::factory()->count(self::LIMIT)->create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
            'task_type' => TaskType::B,
            'completed_at' => null,
        ]);

        $this->submit([$collection->pid])->assertCreated();

        $this->assertNotNull($this->submittedTaskFor($collection)->failed_at);
    }

    public function test_blocking_a_collection_is_recorded_in_the_activity_log(): void
    {
        Feature::activate(CollectionBacklogLimiter::FEATURE);

        $collection = Collection::factory()->bunny()->create();
        $this->fillBacklog($collection, self::LIMIT);

        $this->submit([$collection->pid])->assertCreated();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'collections',
            'description' => 'collection_task_backlog_blocked',
            'event' => 'task_backlog_blocked',
            'subject_id' => $collection->id,
            'properties->pending_tasks' => self::LIMIT,
            'properties->limit' => self::LIMIT,
        ]);
    }
}
