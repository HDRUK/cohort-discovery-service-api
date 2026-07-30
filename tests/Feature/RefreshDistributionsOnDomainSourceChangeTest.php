<?php

namespace Tests\Feature;

use App\Jobs\RefreshLatestDistributionsView;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class RefreshDistributionsOnDomainSourceChangeTest extends TestCase
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

    public function test_toggling_an_unrelated_flag_does_not_dispatch_a_view_refresh(): void
    {
        Queue::fake();

        Feature::activateForEveryone('some-other-feature');

        Queue::assertNotPushed(RefreshLatestDistributionsView::class);
    }
}
