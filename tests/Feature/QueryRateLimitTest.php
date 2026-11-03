<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Custodian;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class QueryRateLimitTest extends TestCase
{
    private const BASE_URL = '/api/v1/queries';

    private const RERUN_URL = '/api/v1/query/re-run';

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
        DB::table('custodian_has_users')->truncate();
        DB::table('features')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('api.monthly_query_limit', 1);

        $this->user = User::factory()->create();
        $this->user->assignRole('user');
    }

    private function submitPayload(): array
    {
        $collection = Collection::factory()->bunny()->create();

        return [
            'name' => 'Test Query',
            'definition' => ['some' => 'definition'],
            'collection_filter' => [$collection->pid],
            'task_type' => TaskType::A,
        ];
    }

    public function test_flag_off_allows_unlimited_submissions(): void
    {
        Feature::deactivate('query-rate-limit');

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();
    }

    public function test_flag_on_blocks_once_limit_reached(): void
    {
        Feature::activate('query-rate-limit');

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertStatus(429);
    }

    public function test_admin_role_is_exempt(): void
    {
        Feature::activate('query-rate-limit');
        $this->user->syncRoles(['admin']);

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();
    }

    public function test_custodian_is_exempt(): void
    {
        Feature::activate('query-rate-limit');
        $this->user->custodians()->attach(Custodian::factory()->create()->id);

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();
    }

    public function test_duplicate_and_rerun_counts_towards_limit(): void
    {
        Feature::activate('query-rate-limit');

        $created = $this->actingAsJwt($this->user)
            ->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated()
            ->json('data');

        $queryPid = $created['query_pid'];

        $this->actingAsJwt($this->user)
            ->getJson(self::RERUN_URL.'/'.$queryPid)
            ->assertStatus(429);
    }

    public function test_count_resets_across_calendar_month_boundary(): void
    {
        Feature::activate('query-rate-limit');

        Carbon::setTestNow(now()->startOfMonth()->subMonth());
        Query::factory()->create(['user_id' => $this->user->id]);
        Carbon::setTestNow();

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertStatus(429);
    }

    public function test_rate_limit_hit_is_logged(): void
    {
        Feature::activate('query-rate-limit');
        DB::table('activity_log')->truncate();

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertStatus(429);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'queries',
            'description' => 'query_rate_limit_exceeded',
            'causer_id' => $this->user->id,
            'properties->monthly_limit' => 1,
        ]);
    }

    public function test_user_payload_includes_query_quota(): void
    {
        Feature::activate('query-rate-limit');

        $this->actingAsJwt($this->user)->postJson(self::BASE_URL, $this->submitPayload())
            ->assertCreated();

        $this->actingAsJwt($this->user)->getJson('/api/v1/user')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'query_quota' => [
                        'limit' => 1,
                        'used' => 1,
                        'remaining' => 0,
                        'unlimited' => false,
                    ],
                ],
            ]);
    }

    public function test_user_payload_shows_unlimited_quota_for_exempt_user(): void
    {
        Feature::activate('query-rate-limit');
        $this->user->syncRoles(['admin']);

        $this->actingAsJwt($this->user)->getJson('/api/v1/user')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'query_quota' => [
                        'limit' => 1,
                        'used' => 0,
                        'remaining' => null,
                        'unlimited' => true,
                    ],
                ],
            ]);
    }
}
