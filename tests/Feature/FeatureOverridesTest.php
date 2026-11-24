<?php

namespace Tests\Feature;

use App\Jobs\RefreshLatestDistributionsView;
use App\Models\User;
use App\Models\Workgroup;
use App\Services\Features\FeatureResolver;
use Database\Seeders\FeatureSeeder;
use DB;
use Illuminate\Database\Eloquent\Model;
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

    public function test_the_resolver_does_not_query_per_user(): void
    {
        User::factory()->count(4)->create();
        $users = User::with('workgroups')->get();
        $resolver = app(FeatureResolver::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $users->each(fn (User $user) => $resolver->active(self::OTHER_FLAG, $user));

        $this->assertCount(1, DB::getQueryLog());
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

    public function test_seeding_a_new_flag_does_not_dispatch_a_distributions_refresh(): void
    {
        Queue::fake();

        $this->seed(FeatureSeeder::class);

        Queue::assertNotPushed(RefreshLatestDistributionsView::class);
        $this->assertOverride(null, 'false', 'distribution-use-central-domain');
    }

    public function test_admin_can_list_every_flag_with_its_overrides(): void
    {
        Feature::for($this->target)->activate(self::FLAG);
        Feature::for($this->workgroup)->deactivate(self::FLAG);

        $this->actingAsJwt($this->admin)
            ->getJson(self::BASE_URL.'/overrides')
            ->assertOk()
            ->assertExactJson([
                'message' => 'success',
                'data' => [
                    [
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
                    ],
                    [
                        'feature' => self::OTHER_FLAG,
                        'global' => true,
                        'overridable' => true,
                        'users' => [],
                        'workgroups' => [],
                    ],
                ],
            ]);
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
        $this->actingAsJwt($this->target)->getJson(self::BASE_URL.'/overrides')->assertForbidden();

        $this->actingAsJwt($this->target)
            ->putJson(self::BASE_URL.'/'.self::FLAG.'/overrides', [
                'users' => [['id' => $this->target->id, 'enabled' => true]],
                'workgroups' => [],
            ])
            ->assertForbidden();

        $this->assertOverride($this->target, null);
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
