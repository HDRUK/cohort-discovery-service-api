<?php

namespace Tests\Unit;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Custodian;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use App\Services\Submitters\QuerySubmissionService;
use DB;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\GrantsCollectionAccess;

class QuerySubmissionServiceTest extends TestCase
{
    use GrantsCollectionAccess;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Query::truncate();
        Collection::truncate();
        Task::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->resetCollectionAccess();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->user = User::factory()->create();
        $this->user->assignRole('user');
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

        $service = app(QuerySubmissionService::class);

        // Run
        $result = $service->handle($data, $this->admin->id);

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

        $service = app(QuerySubmissionService::class);
        $result = $service->handle($data, $this->admin->id);

        $this->assertArrayHasKey('query_pid', $result);
        $this->assertEquals(0, $result['task_count']);
    }

    public function test_it_refuses_a_collection_outside_the_users_workgroups(): void
    {
        $hidden = $this->activeCollection();

        $this->assertRefused([$hidden->pid]);
    }

    public function test_it_refuses_when_any_requested_collection_is_hidden(): void
    {
        $visible = $this->activeCollection();
        $hidden = $this->activeCollection();
        $this->grantCollectionAccess($this->user, $visible);

        $this->assertRefused([$visible->pid, $hidden->pid]);
    }

    public function test_it_refuses_an_inactive_collection_to_a_workgroup_member(): void
    {
        $draft = Collection::factory()->create();
        $this->grantCollectionAccess($this->user, $draft);
        $draft->setState(Collection::STATUS_DRAFT);

        $this->assertRefused([$draft->pid]);
    }

    public function test_workgroup_member_can_submit_to_their_workgroups_collection(): void
    {
        $visible = $this->activeCollection();
        $this->grantCollectionAccess($this->user, $visible);

        $this->submitAs($this->user, [$visible->pid]);

        $this->assertSame([$visible->id], Task::pluck('collection_id')->all());
    }

    public function test_empty_filter_only_targets_visible_collections(): void
    {
        $visible = $this->activeCollection();
        $this->activeCollection();
        $this->grantCollectionAccess($this->user, $visible);

        $this->submitAs($this->user, []);

        $this->assertSame([$visible->id], Task::pluck('collection_id')->all());
    }

    public function test_custodian_member_can_submit_to_their_custodians_draft_collection(): void
    {
        $custodian = Custodian::factory()->create();
        $collection = Collection::factory()->create(['custodian_id' => $custodian->id]);
        $collection->setState(Collection::STATUS_DRAFT);
        $this->user->custodians()->attach($custodian->id);

        $this->submitAs($this->user, [$collection->pid]);

        $this->assertSame([$collection->id], Task::pluck('collection_id')->all());
    }

    public function test_soft_deleted_collection_is_ignored(): void
    {
        $collection = $this->activeCollection();
        $this->grantCollectionAccess($this->user, $collection);
        $collection->delete();

        $result = $this->submitAs($this->user, [$collection->pid]);

        $this->assertSame(0, $result['task_count']);
    }

    private function activeCollection(): Collection
    {
        $collection = Collection::factory()->create();
        $collection->setState(Collection::STATUS_ACTIVE);

        return $collection;
    }

    private function submitAs(User $user, array $collectionPids): array
    {
        return app(QuerySubmissionService::class)->handle([
            'name' => 'Visibility test',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collectionPids,
            'task_type' => TaskType::A->value,
        ], $user->id);
    }

    private function assertRefused(array $collectionPids): void
    {
        $this->assertThrows(
            fn () => $this->submitAs($this->user, $collectionPids),
            AuthorizationException::class
        );

        $this->assertDatabaseCount('queries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }
}
