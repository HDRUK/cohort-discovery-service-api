<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Query;
use App\Models\RegressionTest;
use App\Models\Task;
use App\Models\User;
use DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegressionTestTest extends TestCase
{
    private const BASE_URL = '/api/v1/regression-tests';
    private const ADMIN_INDEX_URL = '/api/v1/admin/regression-tests';
    private const USER_INDEX_URL = '/api/v1/user/regression-tests';

    private User $admin;
    private User $user;
    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMiddleware();
        $this->enableObservers();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Query::truncate();
        Collection::truncate();
        Task::truncate();
        DB::table('regression_tests')->truncate();
        DB::table('regression_test_collection')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->user = User::factory()->create();

        $this->collection = Collection::factory()->create();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My Regression Test',
            'query_definition' => [['field' => 'age', 'operator' => '>', 'value' => '18']],
            'collections' => [
                ['pid' => $this->collection->pid, 'expected_result' => 42],
            ],
        ], $overrides);
    }

    // --- GET /admin/regression-tests ---

    public function test_admin_can_list_global_regression_tests(): void
    {
        RegressionTest::factory()->count(2)->create(['user_id' => null]);

        $response = $this->actingAsJwt($this->admin)->getJson(self::ADMIN_INDEX_URL);

        $response->assertOk()
            ->assertJsonStructure(['data' => [['pid', 'name', 'query', 'collections']]]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_non_admin_cannot_list_global_regression_tests(): void
    {
        $response = $this->actingAsJwt($this->user)->getJson(self::ADMIN_INDEX_URL);

        $response->assertForbidden();
    }

    // --- GET /user/regression-tests ---

    public function test_user_index_returns_only_tests_owned_by_the_authenticated_user(): void
    {
        $ownTest = RegressionTest::factory()->forUser($this->user->id)->create();
        RegressionTest::factory()->create(['user_id' => null]); // global — should not appear

        $response = $this->actingAsJwt($this->user)->getJson(self::USER_INDEX_URL);

        $response->assertOk();

        $pids = collect($response->json('data'))->pluck('pid');
        $this->assertCount(1, $pids);
        $this->assertTrue($pids->contains($ownTest->pid));
    }

    // --- POST /regression-tests ---

    public function test_admin_can_create_a_regression_test(): void
    {
        $response = $this->actingAsJwt($this->admin)->postJson(self::BASE_URL, $this->validPayload());

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['pid', 'name', 'query', 'collections']]);

        $this->assertDatabaseHas('regression_tests', [
            'name' => 'My Regression Test',
            'user_id' => null,
        ]);
    }

    public function test_admin_create_links_collection_with_expected_result(): void
    {
        $response = $this->actingAsJwt($this->admin)->postJson(self::BASE_URL, $this->validPayload());

        $response->assertCreated();

        $test = RegressionTest::where('pid', $response->json('data.pid'))->firstOrFail();

        $this->assertDatabaseHas('regression_test_collection', [
            'regression_test_id' => $test->id,
            'collection_id' => $this->collection->id,
            'expected_result' => 42,
        ]);
    }

    public function test_create_also_creates_a_linked_query(): void
    {
        $response = $this->actingAsJwt($this->admin)->postJson(self::BASE_URL, $this->validPayload());

        $response->assertCreated();

        $this->assertDatabaseHas('queries', ['name' => 'My Regression Test']);
        $this->assertNotNull($response->json('data.query.pid'));
    }

    public function test_non_admin_cannot_create_a_regression_test(): void
    {
        $response = $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->validPayload());

        $response->assertForbidden();
    }

    public function test_create_validates_required_fields(): void
    {
        $response = $this->actingAsJwt($this->admin)->postJson(self::BASE_URL, []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'query_definition', 'collections']);
    }

    public function test_create_validates_collection_pid_exists(): void
    {
        $payload = $this->validPayload([
            'collections' => [['pid' => (string) Str::uuid(), 'expected_result' => 10]],
        ]);

        $response = $this->actingAsJwt($this->admin)->postJson(self::BASE_URL, $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['collections.0.pid']);
    }

    // --- GET /regression-tests/{pid} ---

    public function test_admin_can_view_a_regression_test(): void
    {
        $test = RegressionTest::factory()->create();

        $response = $this->actingAsJwt($this->admin)->getJson(self::BASE_URL.'/'.$test->pid);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['pid', 'name', 'query', 'collections']]);

        $this->assertEquals($test->pid, $response->json('data.pid'));
    }

    public function test_non_admin_cannot_view_a_regression_test(): void
    {
        $test = RegressionTest::factory()->create();

        $response = $this->actingAsJwt($this->user)->getJson(self::BASE_URL.'/'.$test->pid);

        $response->assertNotFound();
    }

    public function test_show_returns_404_for_unknown_pid(): void
    {
        $response = $this->actingAsJwt($this->admin)->getJson(self::BASE_URL.'/'.(string) Str::uuid());

        $response->assertNotFound();
    }

    // --- PUT /regression-tests/{pid} ---

    public function test_admin_can_update_the_name_of_a_regression_test(): void
    {
        $test = RegressionTest::factory()->create(['name' => 'Original Name']);

        $response = $this->actingAsJwt($this->admin)->putJson(self::BASE_URL.'/'.$test->pid, [
            'name' => 'Updated Name',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('regression_tests', [
            'id' => $test->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_admin_can_sync_collections_on_a_regression_test(): void
    {
        $test = RegressionTest::factory()->create();
        $test->collections()->attach([$this->collection->id => ['expected_result' => 10]]);

        $newCollection = Collection::factory()->create();

        $response = $this->actingAsJwt($this->admin)->putJson(self::BASE_URL.'/'.$test->pid, [
            'collections' => [
                ['pid' => $newCollection->pid, 'expected_result' => 99],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('regression_test_collection', [
            'regression_test_id' => $test->id,
            'collection_id' => $this->collection->id,
        ]);

        $this->assertDatabaseHas('regression_test_collection', [
            'regression_test_id' => $test->id,
            'collection_id' => $newCollection->id,
            'expected_result' => 99,
        ]);
    }

    public function test_non_admin_cannot_update_a_regression_test(): void
    {
        $test = RegressionTest::factory()->create();

        $response = $this->actingAsJwt($this->user)->putJson(self::BASE_URL.'/'.$test->pid, [
            'name' => 'New Name',
        ]);

        $response->assertNotFound();
    }

    // --- POST /regression-tests/{pid}/run ---

    public function test_admin_can_run_a_regression_test(): void
    {
        $test = RegressionTest::factory()->create();
        $test->collections()->attach([$this->collection->id => ['expected_result' => null]]);

        $response = $this->actingAsJwt($this->admin)->postJson(self::BASE_URL.'/'.$test->pid.'/run');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['task_count', 'task_pids']]);

        $this->assertEquals(1, $response->json('data.task_count'));
        $this->assertDatabaseHas(Task::class, [
            'query_id' => $test->query_id,
            'collection_id' => $this->collection->id,
        ]);
    }

    public function test_run_creates_one_task_per_linked_collection(): void
    {
        $second = Collection::factory()->create();

        $test = RegressionTest::factory()->create();
        $test->collections()->attach([
            $this->collection->id => ['expected_result' => null],
            $second->id => ['expected_result' => null],
        ]);

        $response = $this->actingAsJwt($this->admin)->postJson(self::BASE_URL.'/'.$test->pid.'/run');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.task_count'));
        $this->assertDatabaseHas(Task::class, ['query_id' => $test->query_id, 'collection_id' => $this->collection->id]);
        $this->assertDatabaseHas(Task::class, ['query_id' => $test->query_id, 'collection_id' => $second->id]);
    }

    public function test_non_admin_cannot_run_a_regression_test(): void
    {
        $test = RegressionTest::factory()->create();

        $response = $this->actingAsJwt($this->user)->postJson(self::BASE_URL.'/'.$test->pid.'/run');

        $response->assertNotFound();
    }
}
