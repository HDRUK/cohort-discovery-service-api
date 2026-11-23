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
    private const SCOPABLE = 'query-builder-use-location';

    private const GLOBAL_ONLY = 'query-rate-limit';

    private User $user;

    private FeatureResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'database');
        config()->set('features.scopable', [self::SCOPABLE]);

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
        Feature::activate(self::SCOPABLE);

        $this->assertTrue($this->resolver->active(self::SCOPABLE, $this->user));
    }

    public function test_workgroup_override_on_beats_global_off(): void
    {
        Feature::deactivate(self::SCOPABLE);
        $workgroup = $this->workgroupWithUser('UK-RESEARCH-TEST');
        Feature::for($workgroup)->activate(self::SCOPABLE);

        $this->assertTrue($this->resolver->active(self::SCOPABLE, $this->user));
    }

    public function test_workgroup_override_off_beats_global_on(): void
    {
        Feature::activate(self::SCOPABLE);
        $workgroup = $this->workgroupWithUser('UK-INDUSTRY-TEST');
        Feature::for($workgroup)->deactivate(self::SCOPABLE);

        $this->assertFalse($this->resolver->active(self::SCOPABLE, $this->user));
    }

    public function test_any_workgroup_on_wins_over_another_workgroup_off(): void
    {
        Feature::deactivate(self::SCOPABLE);
        Feature::for($this->workgroupWithUser('UK-INDUSTRY-TEST'))->deactivate(self::SCOPABLE);
        Feature::for($this->workgroupWithUser('UK-RESEARCH-TEST'))->activate(self::SCOPABLE);

        $this->assertTrue($this->resolver->active(self::SCOPABLE, $this->user));
    }

    public function test_user_override_off_beats_workgroup_on(): void
    {
        Feature::deactivate(self::SCOPABLE);
        Feature::for($this->workgroupWithUser('UK-RESEARCH-TEST'))->activate(self::SCOPABLE);
        Feature::for($this->user)->deactivate(self::SCOPABLE);

        $this->assertFalse($this->resolver->active(self::SCOPABLE, $this->user));
    }

    public function test_user_override_on_beats_global_off(): void
    {
        Feature::deactivate(self::SCOPABLE);
        Feature::for($this->user)->activate(self::SCOPABLE);

        $this->assertTrue($this->resolver->active(self::SCOPABLE, $this->user));
    }

    public function test_workgroup_overrides_do_not_apply_to_non_members(): void
    {
        Feature::deactivate(self::SCOPABLE);
        $workgroup = Workgroup::firstOrCreate(['name' => 'UK-RESEARCH-TEST']);
        Feature::for($workgroup)->activate(self::SCOPABLE);

        $this->assertFalse($this->resolver->active(self::SCOPABLE, $this->user));
    }

    public function test_overrides_are_ignored_for_flags_that_are_not_scopable(): void
    {
        Feature::deactivate(self::GLOBAL_ONLY);
        Feature::for($this->user)->activate(self::GLOBAL_ONLY);

        $this->assertFalse($this->resolver->active(self::GLOBAL_ONLY, $this->user));
    }

    public function test_clearing_a_user_override_falls_back_to_the_workgroup(): void
    {
        Feature::deactivate(self::SCOPABLE);
        Feature::for($this->workgroupWithUser('UK-RESEARCH-TEST'))->activate(self::SCOPABLE);
        Feature::for($this->user)->deactivate(self::SCOPABLE);

        Feature::for($this->user)->forget(self::SCOPABLE);

        $this->assertTrue($this->resolver->active(self::SCOPABLE, $this->user));
    }

    public function test_without_a_user_only_the_global_value_applies(): void
    {
        Feature::deactivate(self::SCOPABLE);
        Feature::for($this->user)->activate(self::SCOPABLE);

        $this->assertFalse($this->resolver->active(self::SCOPABLE, null));
    }

    public function test_all_for_resolves_every_flag_for_the_user(): void
    {
        Feature::deactivate(self::SCOPABLE);
        Feature::activate(self::GLOBAL_ONLY);
        Feature::for($this->user)->activate(self::SCOPABLE);

        $this->assertSame(
            [self::SCOPABLE => true, self::GLOBAL_ONLY => true],
            collect($this->resolver->allFor($this->user))->sortKeys()->all()
        );
    }

    private function workgroupWithUser(string $name): Workgroup
    {
        $workgroup = Workgroup::firstOrCreate(['name' => $name]);
        $this->user->workgroups()->syncWithoutDetaching([$workgroup->id]);

        return $workgroup;
    }
}
