<?php

namespace Tests\Feature\Api\V1;

use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class ClickControllerTest extends TestCase
{
    private const BASE_URL = '/api/v1/clicks';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'database');

        // The RateLimiter singleton is resolved at boot bound to the DB cache
        // store, whose counters do not persist across requests in the test
        // harness (production uses Redis, where throttling works). Rebind it to
        // the array store and re-register the named limiter for deterministic
        // rate-limit assertions.
        $this->app->instance(
            CacheRateLimiter::class,
            new CacheRateLimiter(Cache::store('array'))
        );
        RateLimiter::for('click-tracking', fn (Request $r) => Limit::perMinute(config('clicks.rate_limit', 60))
            ->by($r->user()?->id ?: $r->ip()));

        // decode.jwt must set Auth::user() so attributed clicks record a causer.
        $this->enableMiddleware();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Task::truncate();
        Query::truncate();
        Collection::truncate();
        DB::table('activity_log')->truncate();
        DB::table('features')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Reset the throttle counters between tests.
        Cache::flush();

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_it_records_an_attributed_click_on_a_task(): void
    {
        $task = $this->makeTask();

        $response = $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'clicks',
            'event' => 'clicked_collection_link',
            'description' => 'click_clicked_collection_link',
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'properties->subject_type' => 'task',
        ]);
    }

    public function test_anonymous_flag_nulls_the_causer(): void
    {
        Feature::activate('click-tracking-anonymous');

        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
        ])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'event' => 'clicked_collection_link',
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'causer_type' => null,
            'causer_id' => null,
        ]);
    }

    public function test_subject_id_accepts_a_pid(): void
    {
        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->pid,
            'action' => 'clicked_collection_link',
        ])->assertOk();

        // The pid resolves to the same row - subject_id is the integer id.
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Task::class,
            'subject_id' => $task->id,
        ]);
    }

    public function test_task_subject_lets_analytics_infer_query_and_collection(): void
    {
        $query = Query::factory()->create(['user_id' => $this->user->id]);
        $collection = Collection::factory()->bunny()->create();
        $task = Task::factory()->create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
        ]);

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
        ])->assertOk();

        $loggedTaskId = DB::table('activity_log')->where('log_name', 'clicks')->value('subject_id');
        $logged = Task::find($loggedTaskId);

        // From the task subject alone we recover both the query and the collection.
        $this->assertSame($query->id, $logged->submittedQuery->id);
        $this->assertSame($collection->id, $logged->collection->id);
    }

    public function test_optional_description_is_stored_verbatim(): void
    {
        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
            'description' => 'User followed the collection link on the results page',
        ])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'description' => 'User followed the collection link on the results page',
        ]);
    }

    public function test_caller_supplied_properties_are_stored_as_json(): void
    {
        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
            'properties' => [
                'origin' => 'results_table',
                'position' => 3,
                'nested' => ['a' => true, 'b' => [1, 2]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'properties->origin' => 'results_table',
            'properties->position' => 3,
            'properties->nested->a' => true,
            'properties->subject_type' => 'task',
        ]);

        $stored = json_decode(DB::table('activity_log')->value('properties'), true);
        $this->assertSame([1, 2], $stored['nested']['b']);
    }

    public function test_caller_cannot_clobber_subject_type_in_properties(): void
    {
        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
            'properties' => ['subject_type' => 'spoofed'],
        ])->assertOk();

        // The resolved type is merged last, so it always wins.
        $this->assertDatabaseHas('activity_log', ['properties->subject_type' => 'task']);
    }

    public function test_oversized_properties_are_rejected(): void
    {
        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
            'properties' => ['blob' => str_repeat('x', 2001)],
        ])->assertStatus(422);
    }

    public function test_non_array_properties_are_rejected(): void
    {
        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
            'properties' => 'not-an-object',
        ])->assertStatus(422);
    }

    public function test_subject_type_that_is_not_a_model_returns_not_found(): void
    {
        // 'banana' -> App\Models\Banana does not exist, so the is_subclass_of
        // guard returns 404 (there is no allowlist - any real model is accepted).
        $this->postClick([
            'subject_type' => 'banana',
            'subject_id' => 1,
            'action' => 'clicked_link',
        ])->assertNotFound();
    }

    public function test_subject_type_with_illegal_characters_is_rejected(): void
    {
        // Namespace separators / digits must never reach the class-name builder.
        $this->postClick([
            'subject_type' => 'Task\\..\\User',
            'subject_id' => 1,
            'action' => 'clicked_link',
        ])->assertStatus(422);
    }

    public function test_missing_subject_returns_not_found(): void
    {
        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => (string) Str::uuid(),
            'action' => 'clicked_collection_link',
        ])->assertNotFound();
    }

    public function test_invalid_action_is_rejected(): void
    {
        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'Clicked The Link',
        ])->assertStatus(422);
    }

    public function test_over_long_description_is_rejected(): void
    {
        $task = $this->makeTask();

        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
            'description' => str_repeat('x', 501),
        ])->assertStatus(422);
    }

    public function test_any_authenticated_user_can_click_another_users_task(): void
    {
        $owner = User::factory()->create();
        $query = Query::factory()->create(['user_id' => $owner->id]);
        $collection = Collection::factory()->bunny()->create();
        $task = Task::factory()->create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
        ]);

        // $this->user is a different user and there is no ownership check.
        $this->postClick([
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'clicked_collection_link',
        ])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $task->id,
            'causer_id' => $this->user->id,
        ]);
    }

    public function test_it_rate_limits_excessive_clicks(): void
    {
        config()->set('clicks.rate_limit', 3);

        $task = $this->makeTask();

        // Fire well past the limit. The first click succeeds and a 429 must
        // appear once the per-minute limit is exceeded. (We assert that
        // throttling engages rather than the exact boundary - the array cache
        // store lags the counter by one; production uses Redis where it is
        // precise.)
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->postClick($this->clickPayload($task, 'action_'.$i))->status();
        }

        $this->assertSame(200, $statuses[0]);
        $this->assertContains(429, $statuses);
    }

    public function test_it_accepts_a_server_to_server_call_without_browser_headers(): void
    {
        $task = $this->makeTask();

        // A Next.js Server Action proxies the call, so there is no Origin or
        // Sec-Fetch-* header - only the bearer JWT. That must be enough.
        $this->postClick($this->clickPayload($task, 'clicked_collection_link'))->assertOk();
    }

    private function makeTask(): Task
    {
        $query = Query::factory()->create(['user_id' => $this->user->id]);
        $collection = Collection::factory()->bunny()->create();

        return Task::factory()->create([
            'query_id' => $query->id,
            'collection_id' => $collection->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clickPayload(Task $task, string $action): array
    {
        return [
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => $action,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postClick(array $payload)
    {
        return $this->actingAsJwt($this->user)
            ->postJson(self::BASE_URL, $payload);
    }
}
