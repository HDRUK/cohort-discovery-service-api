<?php

namespace Tests\Feature;

use App\Jobs\RefreshLatestDistributionsView;
use App\Models\User;
use App\Models\Workgroup;
use App\Services\Features\FeatureResolver;
use Database\Seeders\FeatureSeeder;
use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeatureOverridesTest extends TestCase
{
    private const BASE_URL = '/api/v1/features';

    private const FLAG = 'query-builder-use-location';

    private const OTHER_FLAG = 'query-rate-limit';

    private const GLOBAL_ONLY_FLAG = 'click-tracking-anonymous';

    private User $admin;

    private User $target;

    private Workgroup $workgroup;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'database');

        $this->enableMiddleware();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        DB::table('user_has_workgroups')->truncate();
        DB::table('features')->truncate();
        DB::table('activity_log')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->target = User::factory()->create();
        $this->workgroup = Workgroup::firstOrCreate(['name' => 'UK-RESEARCH-TEST']);

        Feature::deactivate(self::FLAG);
        Feature::activate(self::OTHER_FLAG);
    }

    public static function resolutionCases(): array
    {
        return [
            'global applies without overrides' => [true, null, [], null, true],
            'workgroup on beats global off' => [false, null, [true], null, true],
            'workgroup off beats global on' => [true, null, [false], null, false],
            'any workgroup on beats another off' => [false, null, [false, true], null, true],
            'user off beats workgroup on' => [false, false, [true], null, false],
            'user on beats global off' => [false, true, [], null, true],
            'workgroup the user is not in is ignored' => [false, null, [], true, false],
        ];
    }

    #[DataProvider('resolutionCases')]
    public function test_a_flag_resolves_by_user_then_workgroup_then_global(
        bool $global,
        ?bool $userOverride,
        array $memberWorkgroups,
        ?bool $nonMemberWorkgroup,
        bool $expected
    ): void {
        $global ? Feature::activate(self::FLAG) : Feature::deactivate(self::FLAG);

        if ($userOverride !== null) {
            $this->setOverride($this->target, $userOverride);
        }

        foreach ($memberWorkgroups as $index => $value) {
            $workgroup = Workgroup::create(['name' => 'MEMBER-'.$index]);
            $this->target->workgroups()->attach($workgroup->id);
            $this->setOverride($workgroup, $value);
        }

        if ($nonMemberWorkgroup !== null) {
            $this->setOverride($this->workgroup, $nonMemberWorkgroup);
        }

        $this->assertSame($expected, app(FeatureResolver::class)->active(self::FLAG, $this->target));
    }

    public function test_overrides_are_ignored_on_a_flag_that_is_not_overridable(): void
    {
        Feature::activate(self::GLOBAL_ONLY_FLAG);
        Feature::for($this->target)->deactivate(self::GLOBAL_ONLY_FLAG);

        $this->assertTrue(app(FeatureResolver::class)->active(self::GLOBAL_ONLY_FLAG, $this->target));

        $this->replaceOverrides(self::GLOBAL_ONLY_FLAG, [
            'users' => [['id' => $this->target->id, 'enabled' => false]],
            'workgroups' => [],
        ])->assertUnprocessable();

        $listed = collect($this->actingAsJwt($this->admin)->getJson(self::BASE_URL.'/overrides')->json('data'))
            ->keyBy('feature');

        $this->assertFalse($listed[self::GLOBAL_ONLY_FLAG]['overridable']);
        $this->assertTrue($listed[self::FLAG]['overridable']);
    }

    public function test_the_features_endpoint_returns_the_callers_effective_values(): void
    {
        $other = User::factory()->create();
        $on = Workgroup::create(['name' => 'EFFECTIVE-ON']);
        $off = Workgroup::create(['name' => 'EFFECTIVE-OFF']);
        $this->target->workgroups()->attach([$on->id, $off->id]);
        Feature::for($on)->activate(self::FLAG);
        Feature::for($off)->deactivate(self::FLAG);
        Feature::for($this->target)->deactivate(self::OTHER_FLAG);

        $targetFlags = $this->actingAsJwt($this->target)->getJson(self::BASE_URL)->assertOk()->json('data');
        $otherFlags = $this->actingAsJwt($other)->getJson(self::BASE_URL)->assertOk()->json('data');

        $this->assertTrue($targetFlags[self::FLAG]);
        $this->assertFalse($targetFlags[self::OTHER_FLAG]);
        $this->assertFalse($otherFlags[self::FLAG]);
        $this->assertTrue($otherFlags[self::OTHER_FLAG]);
    }

    public function test_a_defined_flag_without_a_row_resolves_to_its_config_default(): void
    {
        $this->assertTrue(app(FeatureResolver::class)->active('query-builder', $this->target));
        $this->assertFalse(app(FeatureResolver::class)->active('in-app-messenger', $this->target));

        $flags = $this->actingAsJwt($this->target)->getJson(self::BASE_URL)->assertOk()->json('data');

        $this->assertTrue($flags['query-nlp']);
        $this->assertFalse($flags['access-banner']);
        $this->assertSame(array_keys(config('features.flags')), array_keys($flags));
    }

    public function test_reading_a_flag_through_pennant_for_a_user_without_a_row_is_rejected(): void
    {
        $this->expectException(\TypeError::class);

        Feature::for($this->target)->value('in-app-messenger');
    }

    public function test_a_changed_database_value_is_seen_after_the_pennant_cache_is_flushed(): void
    {
        $this->assertFalse(Feature::active(self::FLAG));

        DB::table('features')->where('name', self::FLAG)->update(['value' => 'true']);
        Feature::flushCache();

        $this->assertTrue(Feature::active(self::FLAG));
    }

    public function test_a_finished_queue_job_flushes_the_pennant_cache_even_when_it_failed(): void
    {
        $this->assertFalse(Feature::active(self::FLAG));

        DB::table('features')->where('name', self::FLAG)->update(['value' => 'true']);
        event(new JobAttempted('sync', \Mockery::mock(\Illuminate\Contracts\Queue\Job::class), true));

        $this->assertTrue(Feature::active(self::FLAG));
    }

    public function test_changing_the_central_domain_flag_dispatches_one_refresh(): void
    {
        Queue::fake();

        $this->setGlobal('distribution-use-central-domain', true)->assertOk();
        $this->setGlobal('distribution-use-central-domain', true)->assertOk();

        Queue::assertPushed(RefreshLatestDistributionsView::class, 1);
        $this->assertOverride(null, 'true', 'distribution-use-central-domain');
    }

    public function test_changing_another_flag_or_writing_directly_does_not_dispatch_a_refresh(): void
    {
        Queue::fake();

        $this->setGlobal(self::FLAG, true)->assertOk();
        Feature::activate('distribution-use-central-domain');
        Feature::deactivate('distribution-use-central-domain');

        Queue::assertNotPushed(RefreshLatestDistributionsView::class);
    }

    public function test_the_features_endpoint_runs_a_fixed_number_of_queries(): void
    {
        $this->actingAsJwt($this->target)->getJson(self::BASE_URL)->assertOk();
        User::factory()->count(4)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAsJwt($this->target)->getJson(self::BASE_URL)->assertOk();
        $firstRun = count(DB::getQueryLog());

        User::factory()->count(4)->create();
        DB::flushQueryLog();
        $this->actingAsJwt($this->target)->getJson(self::BASE_URL)->assertOk();

        $this->assertSame($firstRun, count(DB::getQueryLog()));
    }

    public function test_global_toggle_leaves_overrides_in_place(): void
    {
        Feature::for($this->target)->activate(self::FLAG);
        Feature::for($this->workgroup)->deactivate(self::FLAG);

        $this->actingAsJwt($this->admin)
            ->putJson(self::BASE_URL.'/'.self::FLAG, ['enabled' => true])
            ->assertOk();

        $this->assertOverride($this->target, 'true');
        $this->assertOverride($this->workgroup, 'false');
        $this->assertOverride(null, 'true');
    }

    public function test_deleting_a_user_or_workgroup_removes_their_overrides(): void
    {
        $this->enableModelEvents();
        $user = User::factory()->create();
        $workgroup = Workgroup::create(['name' => 'UK-RESEARCH-DELETED']);
        Feature::for($user)->activate(self::FLAG);
        Feature::for($workgroup)->activate(self::FLAG);

        $user->delete();
        $workgroup->delete();

        $this->assertOverride($user, null);
        $this->assertOverride($workgroup, null);
    }

    public function test_seeder_purges_overrides_of_retired_and_non_overridable_flags(): void
    {
        Feature::deactivate('retired-flag');
        Feature::for($this->target)->activate('retired-flag');
        Feature::activate(self::GLOBAL_ONLY_FLAG);
        Feature::for($this->target)->deactivate(self::GLOBAL_ONLY_FLAG);

        $this->seed(FeatureSeeder::class);

        $this->assertDatabaseMissing('features', ['name' => 'retired-flag']);
        $this->assertOverride($this->target, null, self::GLOBAL_ONLY_FLAG);
        $this->assertOverride(null, 'true', self::GLOBAL_ONLY_FLAG);
    }

    public function test_seeding_does_not_dispatch_a_refresh_or_overwrite_existing_values(): void
    {
        Queue::fake();
        Feature::activate('distribution-use-central-domain');
        Feature::for($this->target)->activate(self::FLAG);

        $this->seed(FeatureSeeder::class);

        Queue::assertNotPushed(RefreshLatestDistributionsView::class);
        $this->assertOverride(null, 'true', 'distribution-use-central-domain');
        $this->assertOverride($this->target, 'true');
    }

    public function test_the_admin_listing_includes_flags_that_have_never_been_read(): void
    {
        $listed = collect($this->actingAsJwt($this->admin)->getJson(self::BASE_URL.'/overrides')->json('data'));

        $this->assertSame(
            collect(array_keys(config('features.flags')))->sort()->values()->all(),
            $listed->pluck('feature')->all()
        );
        $this->assertTrue($listed->firstWhere('feature', 'query-builder')['global']);
    }

    public function test_a_user_override_reaches_the_nlp_extractor_through_query_parsing(): void
    {
        config()->set('services.nlp.base_uri', 'http://nlp.test');
        Http::fake(['nlp.test/*' => Http::response(['entities' => [], 'groups' => []])]);
        Feature::deactivate('query-builder-use-stats-in-ordering');
        Feature::for($this->target)->activate('query-builder-use-stats-in-ordering');

        $this->actingAsJwt($this->target)->postJson('/api/v1/parse-query', ['query' => 'diabetes']);

        Http::assertSent(fn ($request) => ($request['use_stats_ordering'] ?? null) === true);
    }

    public function test_admin_can_list_every_flag_with_its_overrides(): void
    {
        Feature::for($this->target)->activate(self::FLAG);
        Feature::for($this->workgroup)->deactivate(self::FLAG);

        $listed = collect($this->actingAsJwt($this->admin)->getJson(self::BASE_URL.'/overrides')->assertOk()->json('data'))
            ->keyBy('feature');

        $this->assertSame([
            'feature' => self::FLAG,
            'global' => false,
            'overridable' => true,
            'users' => [[
                'id' => $this->target->id,
                'name' => $this->target->name,
                'email' => $this->target->email,
                'enabled' => true,
            ]],
            'workgroups' => [[
                'id' => $this->workgroup->id,
                'name' => $this->workgroup->name,
                'enabled' => false,
            ]],
        ], $listed[self::FLAG]);
        $this->assertSame([
            'feature' => self::OTHER_FLAG,
            'global' => true,
            'overridable' => true,
            'users' => [],
            'workgroups' => [],
        ], $listed[self::OTHER_FLAG]);
    }

    public function test_replacing_overrides_sets_changes_and_clears_them_logging_only_changes(): void
    {
        $unchanged = User::factory()->create();
        $removed = User::factory()->create();
        Feature::for($unchanged)->activate(self::FLAG);
        Feature::for($removed)->activate(self::FLAG);
        Feature::for($this->workgroup)->deactivate(self::FLAG);

        $this->replaceOverrides(self::FLAG, [
            'users' => [
                ['id' => $unchanged->id, 'enabled' => true],
                ['id' => $this->target->id, 'enabled' => false],
            ],
            'workgroups' => [
                ['id' => $this->workgroup->id, 'enabled' => true],
            ],
        ])->assertOk();

        $this->assertOverride($unchanged, 'true');
        $this->assertOverride($this->target, 'false');
        $this->assertOverride($removed, null);
        $this->assertOverride($this->workgroup, 'true');
        $this->assertOverride(null, 'false');
        $this->assertDatabaseCount('activity_log', 3);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'feature_flags',
            'event' => 'override_cleared',
            'properties->subject_id' => $removed->id,
            'properties->before->enabled' => true,
        ]);
    }

    public function test_replacing_overrides_validates_its_input(): void
    {
        $this->replaceOverrides('unknown-flag', ['users' => [], 'workgroups' => []])->assertNotFound();
        $this->assertDatabaseMissing('features', ['name' => 'unknown-flag']);

        $this->replaceOverrides(self::FLAG, ['users' => []])->assertUnprocessable();
        $this->replaceOverrides(self::FLAG, [
            'users' => [['id' => 999999, 'enabled' => true]],
            'workgroups' => [],
        ])->assertUnprocessable();
        $this->replaceOverrides(self::FLAG, [
            'users' => [
                ['id' => $this->target->id, 'enabled' => true],
                ['id' => $this->target->id, 'enabled' => false],
            ],
            'workgroups' => [],
        ])->assertUnprocessable();

        $this->assertOverride($this->target, null);
    }

    public function test_non_admin_cannot_manage_overrides(): void
    {
        $this->actingAsJwt($this->target)
            ->getJson(self::BASE_URL.'/overrides')
            ->assertForbidden()
            ->assertExactJson(['message' => 'forbidden', 'data' => null]);

        $this->actingAsJwt($this->target)
            ->putJson(self::BASE_URL.'/'.self::FLAG.'/overrides', [
                'users' => [['id' => $this->target->id, 'enabled' => true]],
                'workgroups' => [],
            ])
            ->assertForbidden();

        $this->assertOverride($this->target, null);
    }

    private function setGlobal(string $feature, bool $enabled): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsJwt($this->admin)
            ->putJson(self::BASE_URL.'/'.$feature, ['enabled' => $enabled]);
    }

    private function setOverride(User|Workgroup $subject, bool $value): void
    {
        $value ? Feature::for($subject)->activate(self::FLAG) : Feature::for($subject)->deactivate(self::FLAG);
    }

    private function enableModelEvents(): void
    {
        $this->enableObservers();
        Model::clearBootedModels();
    }

    private function replaceOverrides(string $feature, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsJwt($this->admin)
            ->putJson(self::BASE_URL.'/'.$feature.'/overrides', $payload);
    }

    private function assertOverride(User|Workgroup|null $subject, ?string $value, string $feature = self::FLAG): void
    {
        $this->assertSame(
            $value,
            DB::table('features')
                ->where('name', $feature)
                ->where('scope', Feature::serializeScope($subject))
                ->value('value')
        );
    }
}
