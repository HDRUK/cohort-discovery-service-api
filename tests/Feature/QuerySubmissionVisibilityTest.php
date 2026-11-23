<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Custodian;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use App\Models\Workgroup;
use DB;
use Tests\TestCase;
use Tests\Traits\GrantsCollectionAccess;

class QuerySubmissionVisibilityTest extends TestCase
{
    use GrantsCollectionAccess;

    private const BASE_URL = '/api/v1/queries';

    private const QUERY_RERUN_URL = '/api/v1/query/re-run';

    private const TASK_RERUN_URL = '/api/v1/task/re-run';

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
        DB::table('user_has_workgroups')->truncate();
        DB::table('workgroup_has_collection')->truncate();
        DB::table('custodian_has_users')->truncate();
        DB::table('features')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->user = User::factory()->create();
        $this->user->assignRole('user');
    }

    public function test_user_cannot_submit_to_a_collection_outside_their_workgroups(): void
    {
        $hidden = $this->activeCollection();

        $this->submit([$hidden->pid])->assertForbidden();

        $this->assertDatabaseCount('queries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_user_cannot_submit_when_any_requested_collection_is_hidden(): void
    {
        $visible = $this->activeCollection();
        $hidden = $this->activeCollection();
        $this->grantCollectionAccess($this->user, $visible);

        $this->submit([$visible->pid, $hidden->pid])->assertForbidden();

        $this->assertDatabaseCount('queries', 0);
    }

    public function test_user_can_submit_to_a_collection_in_their_workgroup(): void
    {
        $visible = $this->activeCollection();
        $this->grantCollectionAccess($this->user, $visible);

        $this->submit([$visible->pid])->assertCreated();

        $this->assertSame([$visible->id], Task::pluck('collection_id')->all());
    }

    public function test_empty_filter_only_targets_visible_collections(): void
    {
        $visible = $this->activeCollection();
        $this->activeCollection();
        $this->grantCollectionAccess($this->user, $visible);

        $this->submit([])->assertCreated();

        $this->assertSame([$visible->id], Task::pluck('collection_id')->all());
    }

    public function test_workgroup_member_cannot_submit_to_an_inactive_collection(): void
    {
        $draft = Collection::factory()->bunny()->create();
        $workgroup = Workgroup::firstOrCreate(['name' => 'TEST-COLLECTION-ACCESS']);
        $this->user->workgroups()->syncWithoutDetaching([$workgroup->id]);
        $workgroup->collections()->syncWithoutDetaching([$draft->id]);
        $draft->setState(Collection::STATUS_DRAFT);

        $this->submit([$draft->pid])->assertForbidden();
    }

    public function test_custodian_member_can_submit_to_their_custodians_collection(): void
    {
        $custodian = Custodian::factory()->create();
        $collection = Collection::factory()->bunny()->create(['custodian_id' => $custodian->id]);
        $this->user->custodians()->attach($custodian->id);

        $this->submit([$collection->pid])->assertCreated();

        $this->assertSame([$collection->id], Task::pluck('collection_id')->all());
    }

    public function test_admin_can_submit_to_any_collection(): void
    {
        $this->user->syncRoles(['admin']);
        $collection = $this->activeCollection();

        $this->submit([$collection->pid])->assertCreated();

        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_query_rerun_is_forbidden_once_a_collection_is_no_longer_visible(): void
    {
        $collection = $this->activeCollection();
        $this->grantCollectionAccess($this->user, $collection);

        $queryPid = $this->submit([$collection->pid])
            ->assertCreated()
            ->json('data.query_pid');

        $this->user->workgroups()->detach();

        $this->actingAsJwt($this->user)
            ->getJson(self::QUERY_RERUN_URL.'/'.$queryPid)
            ->assertForbidden();

        $this->assertDatabaseCount('queries', 1);
    }

    public function test_task_rerun_is_forbidden_for_a_collection_outside_the_users_workgroups(): void
    {
        $task = $this->taskOwnedByUser($this->activeCollection());

        $this->actingAsJwt($this->user)
            ->getJson(self::TASK_RERUN_URL.'/'.$task->pid)
            ->assertForbidden();

        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_task_rerun_is_allowed_for_a_visible_collection(): void
    {
        $collection = $this->activeCollection();
        $this->grantCollectionAccess($this->user, $collection);
        $task = $this->taskOwnedByUser($collection);

        $this->actingAsJwt($this->user)
            ->getJson(self::TASK_RERUN_URL.'/'.$task->pid)
            ->assertOk();

        $this->assertDatabaseCount('tasks', 2);
    }

    private function activeCollection(): Collection
    {
        $collection = Collection::factory()->bunny()->create();
        $collection->setState(Collection::STATUS_ACTIVE);

        return $collection;
    }

    private function taskOwnedByUser(Collection $collection): Task
    {
        $query = Query::factory()->create(['user_id' => $this->user->id]);

        return Task::factory()->create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
        ]);
    }

    private function submit(array $collectionPids): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsJwt($this->user)->postJson(self::BASE_URL, [
            'name' => 'Visibility test',
            'definition' => ['some' => 'definition'],
            'collection_filter' => $collectionPids,
            'task_type' => TaskType::A,
        ]);
    }
}
