<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workgroup;
use Database\Seeders\FeatureSeeder;
use DB;
use Laravel\Pennant\Feature;
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

    public function test_deleting_a_workgroup_removes_its_overrides(): void
    {
        Feature::for($this->workgroup)->activate(self::FLAG);

        $this->workgroup->delete();

        $this->assertDatabaseMissing('features', ['scope' => Feature::serializeScope($this->workgroup)]);
    }

    public function test_deleting_a_user_removes_their_overrides(): void
    {
        Feature::for($this->target)->activate(self::FLAG);

        $this->target->delete();

        $this->assertDatabaseMissing('features', ['scope' => Feature::serializeScope($this->target)]);
    }

    public function test_seeder_purges_overrides_of_retired_flags(): void
    {
        Feature::deactivate('retired-flag');
        Feature::for($this->target)->activate('retired-flag');

        $this->seed(FeatureSeeder::class);

        $this->assertDatabaseMissing('features', ['name' => 'retired-flag']);
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

    public function test_replacing_overrides_sets_changes_and_clears_them(): void
    {
        $kept = User::factory()->create();
        $removed = User::factory()->create();
        Feature::for($kept)->activate(self::FLAG);
        Feature::for($removed)->activate(self::FLAG);
        Feature::for($this->workgroup)->deactivate(self::FLAG);

        $this->replaceOverrides(self::FLAG, [
            'users' => [
                ['id' => $kept->id, 'enabled' => true],
                ['id' => $this->target->id, 'enabled' => false],
            ],
            'workgroups' => [
                ['id' => $this->workgroup->id, 'enabled' => true],
            ],
        ])->assertOk();

        $this->assertOverride($kept, 'true');
        $this->assertOverride($this->target, 'false');
        $this->assertOverride($removed, null);
        $this->assertOverride($this->workgroup, 'true');
        $this->assertOverride(null, 'false');
    }

    public function test_replacing_with_no_overrides_clears_them_all(): void
    {
        Feature::for($this->target)->activate(self::FLAG);
        Feature::for($this->workgroup)->activate(self::FLAG);

        $this->replaceOverrides(self::FLAG, ['users' => [], 'workgroups' => []])->assertOk();

        $this->assertOverride($this->target, null);
        $this->assertOverride($this->workgroup, null);
    }

    public function test_any_flag_can_be_overridden(): void
    {
        $this->replaceOverrides(self::OTHER_FLAG, [
            'users' => [['id' => $this->target->id, 'enabled' => false]],
            'workgroups' => [],
        ])->assertOk();

        $this->assertOverride($this->target, 'false', self::OTHER_FLAG);
    }

    public function test_only_changed_overrides_are_logged(): void
    {
        $unchanged = User::factory()->create();
        Feature::for($unchanged)->activate(self::FLAG);
        Feature::for($this->workgroup)->activate(self::FLAG);

        $this->replaceOverrides(self::FLAG, [
            'users' => [
                ['id' => $unchanged->id, 'enabled' => true],
                ['id' => $this->target->id, 'enabled' => true],
            ],
            'workgroups' => [],
        ])->assertOk();

        $this->assertDatabaseCount('activity_log', 2);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'feature_flags',
            'event' => 'override_set',
            'properties->feature' => self::FLAG,
            'properties->subject_type' => 'user',
            'properties->subject_id' => $this->target->id,
            'properties->after->enabled' => true,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'override_cleared',
            'properties->subject_type' => 'workgroup',
            'properties->subject_id' => $this->workgroup->id,
            'properties->before->enabled' => true,
        ]);
    }

    public function test_listing_marks_global_only_flags(): void
    {
        Feature::deactivate(self::GLOBAL_ONLY_FLAG);

        $flags = collect($this->actingAsJwt($this->admin)
            ->getJson(self::BASE_URL.'/overrides')
            ->assertOk()
            ->json('data'))
            ->keyBy('feature');

        $this->assertFalse($flags[self::GLOBAL_ONLY_FLAG]['overridable']);
        $this->assertTrue($flags[self::FLAG]['overridable']);
    }

    public function test_global_only_flags_cannot_be_overridden(): void
    {
        Feature::activate(self::GLOBAL_ONLY_FLAG);

        $this->replaceOverrides(self::GLOBAL_ONLY_FLAG, [
            'users' => [['id' => $this->target->id, 'enabled' => false]],
            'workgroups' => [],
        ])->assertUnprocessable();

        $this->assertOverride($this->target, null, self::GLOBAL_ONLY_FLAG);
    }

    public function test_seeder_purges_overrides_of_global_only_flags(): void
    {
        Feature::activate(self::GLOBAL_ONLY_FLAG);
        Feature::for($this->target)->deactivate(self::GLOBAL_ONLY_FLAG);

        $this->seed(FeatureSeeder::class);

        $this->assertOverride($this->target, null, self::GLOBAL_ONLY_FLAG);
        $this->assertOverride(null, 'true', self::GLOBAL_ONLY_FLAG);
    }

    public function test_replacing_overrides_of_an_unknown_flag_is_not_found(): void
    {
        $this->replaceOverrides('unknown-flag', ['users' => [], 'workgroups' => []])->assertNotFound();

        $this->assertDatabaseMissing('features', ['name' => 'unknown-flag']);
    }

    public function test_replacing_overrides_rejects_unknown_and_duplicate_subjects(): void
    {
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

        $this->replaceOverrides(self::FLAG, [
            'users' => [['id' => $this->target->id]],
            'workgroups' => [],
        ])->assertUnprocessable();

        $this->assertOverride($this->target, null);
    }

    public function test_replacing_overrides_requires_both_lists(): void
    {
        $this->replaceOverrides(self::FLAG, ['users' => []])->assertUnprocessable();
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
