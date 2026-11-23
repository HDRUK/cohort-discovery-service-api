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

    private const SCOPABLE = 'query-builder-use-location';

    private const GLOBAL_ONLY = 'query-rate-limit';

    private User $admin;

    private User $target;

    private Workgroup $workgroup;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'database');
        config()->set('features.scopable', [self::SCOPABLE]);

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

        Feature::deactivate(self::SCOPABLE);
        Feature::deactivate(self::GLOBAL_ONLY);
    }

    public function test_global_toggle_leaves_overrides_in_place(): void
    {
        Feature::for($this->target)->activate(self::SCOPABLE);
        Feature::for($this->workgroup)->deactivate(self::SCOPABLE);

        $this->actingAsJwt($this->admin)
            ->putJson(self::BASE_URL.'/'.self::SCOPABLE, ['enabled' => true])
            ->assertOk();

        $this->assertOverride($this->target, 'true');
        $this->assertOverride($this->workgroup, 'false');
        $this->assertOverride(null, 'true');
    }

    public function test_deleting_a_workgroup_removes_its_overrides(): void
    {
        Feature::for($this->workgroup)->activate(self::SCOPABLE);

        $this->workgroup->delete();

        $this->assertDatabaseMissing('features', ['scope' => Feature::serializeScope($this->workgroup)]);
    }

    public function test_deleting_a_user_removes_their_overrides(): void
    {
        Feature::for($this->target)->activate(self::SCOPABLE);

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

    public function test_admin_can_list_overrides_of_every_scopable_flag(): void
    {
        Feature::for($this->target)->activate(self::SCOPABLE);
        Feature::for($this->workgroup)->deactivate(self::SCOPABLE);
        Feature::for($this->target)->activate(self::GLOBAL_ONLY);

        $this->actingAsJwt($this->admin)
            ->getJson(self::BASE_URL.'/overrides')
            ->assertOk()
            ->assertExactJson([
                'message' => 'success',
                'data' => [[
                    'feature' => self::SCOPABLE,
                    'global' => false,
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
                ]],
            ]);
    }

    public function test_admin_can_set_and_clear_a_user_override(): void
    {
        $url = $this->overridesUrl().'/users/'.$this->target->id;

        $this->actingAsJwt($this->admin)->putJson($url, ['enabled' => true])->assertOk();

        $this->assertOverride($this->target, 'true');
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'feature_flags',
            'event' => 'override_set',
            'properties->feature' => self::SCOPABLE,
            'properties->subject_type' => 'user',
            'properties->subject_id' => $this->target->id,
            'properties->after->enabled' => true,
        ]);

        $this->actingAsJwt($this->admin)->deleteJson($url)->assertOk();

        $this->assertOverride($this->target, null);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'override_cleared',
            'properties->subject_type' => 'user',
            'properties->before->enabled' => true,
        ]);
    }

    public function test_admin_can_set_and_clear_a_workgroup_override(): void
    {
        $url = $this->overridesUrl().'/workgroups/'.$this->workgroup->id;

        $this->actingAsJwt($this->admin)->putJson($url, ['enabled' => false])->assertOk();

        $this->assertOverride($this->workgroup, 'false');

        $this->actingAsJwt($this->admin)->deleteJson($url)->assertOk();

        $this->assertOverride($this->workgroup, null);
    }

    public function test_clearing_a_missing_override_is_not_logged(): void
    {
        $this->actingAsJwt($this->admin)
            ->deleteJson($this->overridesUrl().'/users/'.$this->target->id)
            ->assertOk();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_non_admin_cannot_manage_overrides(): void
    {
        $url = $this->overridesUrl().'/users/'.$this->target->id;

        $this->actingAsJwt($this->target)->getJson(self::BASE_URL.'/overrides')->assertForbidden();
        $this->actingAsJwt($this->target)->putJson($url, ['enabled' => true])->assertForbidden();
        $this->actingAsJwt($this->target)->deleteJson($url)->assertForbidden();

        $this->assertOverride($this->target, null);
    }

    public function test_flags_that_are_not_scopable_cannot_be_overridden(): void
    {
        $this->actingAsJwt($this->admin)
            ->putJson(self::BASE_URL.'/'.self::GLOBAL_ONLY.'/overrides/users/'.$this->target->id, ['enabled' => true])
            ->assertUnprocessable();

        $this->assertOverride($this->target, null, self::GLOBAL_ONLY);
    }

    public function test_override_for_an_unknown_subject_is_not_found(): void
    {
        $this->actingAsJwt($this->admin)
            ->putJson($this->overridesUrl().'/users/999999', ['enabled' => true])
            ->assertNotFound();
    }

    public function test_override_requires_enabled(): void
    {
        $this->actingAsJwt($this->admin)
            ->putJson($this->overridesUrl().'/users/'.$this->target->id, [])
            ->assertUnprocessable();
    }

    private function overridesUrl(): string
    {
        return self::BASE_URL.'/'.self::SCOPABLE.'/overrides';
    }

    private function assertOverride(User|Workgroup|null $subject, ?string $value, string $feature = self::SCOPABLE): void
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
