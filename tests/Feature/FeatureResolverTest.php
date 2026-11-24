<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workgroup;
use App\Services\Features\FeatureResolver;
use DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class FeatureResolverTest extends TestCase
{
    private const FLAG = 'query-builder-use-location';

    private const OTHER_FLAG = 'query-rate-limit';

    private const GLOBAL_ONLY_FLAG = 'click-tracking-anonymous';

    private User $user;

    private FeatureResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'database');

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        DB::table('user_has_workgroups')->truncate();
        DB::table('features')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->user = User::factory()->create();
        $this->resolver = app(FeatureResolver::class);
    }

    public function test_without_overrides_the_global_value_applies(): void
    {
        Feature::activate(self::FLAG);

        $this->assertTrue($this->resolver->active(self::FLAG, $this->user));
    }

    public function test_workgroup_override_on_beats_global_off(): void
    {
        Feature::deactivate(self::FLAG);
        $workgroup = $this->workgroupWithUser('UK-RESEARCH-TEST');
        Feature::for($workgroup)->activate(self::FLAG);

        $this->assertTrue($this->resolver->active(self::FLAG, $this->user));
    }

    public function test_workgroup_override_off_beats_global_on(): void
    {
        Feature::activate(self::FLAG);
        $workgroup = $this->workgroupWithUser('UK-INDUSTRY-TEST');
        Feature::for($workgroup)->deactivate(self::FLAG);

        $this->assertFalse($this->resolver->active(self::FLAG, $this->user));
    }

    public function test_any_workgroup_on_wins_over_another_workgroup_off(): void
    {
        Feature::deactivate(self::FLAG);
        Feature::for($this->workgroupWithUser('UK-INDUSTRY-TEST'))->deactivate(self::FLAG);
        Feature::for($this->workgroupWithUser('UK-RESEARCH-TEST'))->activate(self::FLAG);

        $this->assertTrue($this->resolver->active(self::FLAG, $this->user));
    }

    public function test_user_override_off_beats_workgroup_on(): void
    {
        Feature::deactivate(self::FLAG);
        Feature::for($this->workgroupWithUser('UK-RESEARCH-TEST'))->activate(self::FLAG);
        Feature::for($this->user)->deactivate(self::FLAG);

        $this->assertFalse($this->resolver->active(self::FLAG, $this->user));
    }

    public function test_user_override_on_beats_global_off(): void
    {
        Feature::deactivate(self::FLAG);
        Feature::for($this->user)->activate(self::FLAG);

        $this->assertTrue($this->resolver->active(self::FLAG, $this->user));
    }

    public function test_workgroup_overrides_do_not_apply_to_non_members(): void
    {
        Feature::deactivate(self::FLAG);
        $workgroup = Workgroup::firstOrCreate(['name' => 'UK-RESEARCH-TEST']);
        Feature::for($workgroup)->activate(self::FLAG);

        $this->assertFalse($this->resolver->active(self::FLAG, $this->user));
    }

    public function test_overrides_apply_to_any_flag(): void
    {
        Feature::deactivate(self::OTHER_FLAG);
        Feature::for($this->user)->activate(self::OTHER_FLAG);

        $this->assertTrue($this->resolver->active(self::OTHER_FLAG, $this->user));
    }

    public function test_clearing_a_user_override_falls_back_to_the_workgroup(): void
    {
        Feature::deactivate(self::FLAG);
        Feature::for($this->workgroupWithUser('UK-RESEARCH-TEST'))->activate(self::FLAG);
        Feature::for($this->user)->deactivate(self::FLAG);

        Feature::for($this->user)->forget(self::FLAG);

        $this->assertTrue($this->resolver->active(self::FLAG, $this->user));
    }

    public function test_without_a_user_only_the_global_value_applies(): void
    {
        Feature::deactivate(self::FLAG);
        Feature::for($this->user)->activate(self::FLAG);

        $this->assertFalse($this->resolver->active(self::FLAG, null));
    }

    public function test_all_for_resolves_every_flag_for_the_user(): void
    {
        Feature::deactivate(self::FLAG);
        Feature::activate(self::OTHER_FLAG);
        Feature::for($this->user)->activate(self::FLAG);

        $this->assertSame(
            [self::FLAG => true, self::OTHER_FLAG => true],
            collect($this->resolver->allFor($this->user))->sortKeys()->all()
        );
    }

    public function test_user_override_is_ignored_on_a_global_only_flag(): void
    {
        Feature::activate(self::GLOBAL_ONLY_FLAG);
        Feature::for($this->user)->deactivate(self::GLOBAL_ONLY_FLAG);

        $this->assertTrue($this->resolver->active(self::GLOBAL_ONLY_FLAG, $this->user));
    }

    public function test_workgroup_override_is_ignored_on_a_global_only_flag(): void
    {
        Feature::activate(self::GLOBAL_ONLY_FLAG);
        Feature::for($this->workgroupWithUser('UK-RESEARCH-TEST'))->deactivate(self::GLOBAL_ONLY_FLAG);

        $this->assertTrue($this->resolver->active(self::GLOBAL_ONLY_FLAG, $this->user));
    }

    public function test_user_override_cannot_lift_the_bunny_v1_constraints(): void
    {
        Feature::activate('constrain-for-bunny-v1');
        Feature::for($this->user)->deactivate('constrain-for-bunny-v1');

        $this->assertTrue($this->resolver->active('constrain-for-bunny-v1', $this->user));
    }

    public function test_all_for_reports_the_global_value_of_a_global_only_flag(): void
    {
        Feature::deactivate(self::GLOBAL_ONLY_FLAG);
        Feature::for($this->user)->activate(self::GLOBAL_ONLY_FLAG);

        $this->assertFalse($this->resolver->allFor($this->user)[self::GLOBAL_ONLY_FLAG]);
    }

    public function test_every_global_only_flag_is_a_known_flag(): void
    {
        $this->assertSame([], array_values(array_diff(
            config('features.global_only'),
            array_keys(config('features.flags'))
        )));
    }

    public function test_a_global_only_flag_is_read_from_the_global_row_alone(): void
    {
        Feature::activate(self::GLOBAL_ONLY_FLAG);
        $this->workgroupWithUser('UK-RESEARCH-TEST');

        DB::enableQueryLog();
        $this->resolver->active(self::GLOBAL_ONLY_FLAG, $this->user);

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_workgroups_already_loaded_on_the_user_are_reused(): void
    {
        Feature::deactivate(self::FLAG);
        Feature::for($this->workgroupWithUser('UK-RESEARCH-TEST'))->activate(self::FLAG);
        $this->user->load('workgroups');

        DB::enableQueryLog();

        $this->assertTrue($this->resolver->active(self::FLAG, $this->user));
        $this->assertCount(1, DB::getQueryLog());
    }

    private function workgroupWithUser(string $name): Workgroup
    {
        $workgroup = Workgroup::firstOrCreate(['name' => $name]);
        $this->user->workgroups()->syncWithoutDetaching([$workgroup->id]);

        return $workgroup;
    }
}
