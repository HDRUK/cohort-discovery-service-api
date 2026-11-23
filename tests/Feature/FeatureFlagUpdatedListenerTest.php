<?php

namespace Tests\Feature;

use App\Jobs\RefreshLatestDistributionsView;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class FeatureFlagUpdatedListenerTest extends TestCase
{
    public function test_toggling_the_domain_source_flag_dispatches_a_view_refresh(): void
    {
        Queue::fake();

        Feature::activateForEveryone('distribution-use-central-domain');

        Queue::assertPushed(RefreshLatestDistributionsView::class);
    }

    public function test_deactivating_the_domain_source_flag_also_dispatches_a_view_refresh(): void
    {
        Queue::fake();

        Feature::deactivateForEveryone('distribution-use-central-domain');

        Queue::assertPushed(RefreshLatestDistributionsView::class);
    }

    public function test_setting_the_global_value_dispatches_a_view_refresh(): void
    {
        Queue::fake();

        Feature::for(null)->activate('distribution-use-central-domain');

        Queue::assertPushed(RefreshLatestDistributionsView::class);
    }

    public function test_a_scoped_override_does_not_dispatch_a_view_refresh(): void
    {
        Queue::fake();

        Feature::for(User::factory()->make(['id' => 123456]))->activate('distribution-use-central-domain');

        Queue::assertNotPushed(RefreshLatestDistributionsView::class);
    }

    public function test_toggling_an_unrelated_flag_does_not_dispatch_a_view_refresh(): void
    {
        Queue::fake();

        Feature::activateForEveryone('some-other-feature');

        Queue::assertNotPushed(RefreshLatestDistributionsView::class);
    }
}
