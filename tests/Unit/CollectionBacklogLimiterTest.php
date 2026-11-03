<?php

use App\Services\Collections\CollectionBacklogLimiter;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class CollectionBacklogLimiterTest extends TestCase
{
    private const LIMIT = 50;

    private const WARNING_LIMIT = 10;

    private CollectionBacklogLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('system.collection_pending_task_limit', self::LIMIT);
        config()->set('system.collection_pending_task_warning_limit', self::WARNING_LIMIT);

        Feature::activate(CollectionBacklogLimiter::FEATURE);

        $this->limiter = app(CollectionBacklogLimiter::class);
    }

    public function test_it_is_saturated_at_and_above_the_limit(): void
    {
        $this->assertFalse($this->limiter->isSaturated(self::LIMIT - 1));
        $this->assertTrue($this->limiter->isSaturated(self::LIMIT));
        $this->assertTrue($this->limiter->isSaturated(self::LIMIT + 1));
    }

    public function test_it_warns_between_the_warning_limit_and_the_limit(): void
    {
        $this->assertFalse($this->limiter->isWarning(self::WARNING_LIMIT - 1));
        $this->assertTrue($this->limiter->isWarning(self::WARNING_LIMIT));
        $this->assertTrue($this->limiter->isWarning(self::LIMIT - 1));
    }

    public function test_it_does_not_warn_once_saturated(): void
    {
        $this->assertFalse($this->limiter->isWarning(self::LIMIT));
    }

    public function test_it_has_drained_only_below_the_warning_limit(): void
    {
        $this->assertTrue($this->limiter->hasDrained(self::WARNING_LIMIT - 1));
        $this->assertFalse($this->limiter->hasDrained(self::WARNING_LIMIT));
        $this->assertFalse($this->limiter->hasDrained(self::LIMIT));
    }

    public function test_a_warning_limit_above_the_limit_is_clamped(): void
    {
        config()->set('system.collection_pending_task_warning_limit', self::LIMIT + 100);

        $this->assertSame(self::LIMIT, $this->limiter->warningLimit());
        $this->assertFalse($this->limiter->isWarning(self::LIMIT - 1));
    }

    public function test_everything_is_inert_when_the_flag_is_off(): void
    {
        Feature::deactivate(CollectionBacklogLimiter::FEATURE);

        $this->assertFalse($this->limiter->isSaturated(self::LIMIT * 10));
        $this->assertFalse($this->limiter->isWarning(self::WARNING_LIMIT));
        $this->assertSame([], $this->limiter->pendingCountsFor([1, 2, 3]));
    }

    public function test_it_returns_no_counts_for_an_empty_id_list(): void
    {
        $this->assertSame([], $this->limiter->pendingCountsFor([]));
    }
}
