<?php

namespace Tests\Unit;

use App\Services\QueryContext\Contexts\Bunny\BunnyQueryContext;
use Carbon\Carbon;
use Tests\TestCase;

class BunnyQueryContextTest extends TestCase
{
    private BunnyQueryContext $bunnyContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bunnyContext = $this->app->make(BunnyQueryContext::class);
    }

    public function test_encode_bunny_time_constraint_returns_null_when_both_bounds_are_null(): void
    {
        $result = $this->bunnyContext->encodeBunnyTimeConstraint(null, null);

        $this->assertNull($result);
    }

    public function test_encode_bunny_time_constraint_with_only_lower_bound_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15'));

        $result = $this->bunnyContext->encodeBunnyTimeConstraint('2023-06-15', null);

        $this->assertSame('|7:TIME:M', $result);

        Carbon::setTestNow();
    }

    public function test_encode_bunny_time_constraint_with_only_upper_bound_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15'));

        $result = $this->bunnyContext->encodeBunnyTimeConstraint(null, '2023-06-15');

        $this->assertSame('7|:TIME:M', $result);

        Carbon::setTestNow();
    }

    public function test_encode_bunny_time_constraint_lower_month_count_matches_relative_diff(): void
    {
        $now = Carbon::parse('2026-03-01');
        Carbon::setTestNow($now);

        $date = $now->copy()->subMonths(5);
        $expectedMonths = $this->bunnyContext->getRelativeMonths($date->toDateString());

        $result = $this->bunnyContext->encodeBunnyTimeConstraint($date->toDateString(), null);

        $this->assertSame("|{$expectedMonths}:TIME:M", $result);

        Carbon::setTestNow();
    }

    public function test_encode_bunny_time_constraint_prefers_lower_when_both_bounds_are_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15'));

        $result = $this->bunnyContext->encodeBunnyTimeConstraint('2023-06-15', '2020-01-15');

        $this->assertSame('|7:TIME:M', $result);

        Carbon::setTestNow();
    }

    public function test_encode_bunny_age_constraint_returns_null_when_both_bounds_are_null(): void
    {
        $result = $this->bunnyContext->encodeBunnyAgeConstraint(null, null);

        $this->assertNull($result);
    }

    public function test_encode_bunny_age_constraint_with_only_lower_bound_set(): void
    {
        $result = $this->bunnyContext->encodeBunnyAgeConstraint(18, null);

        $this->assertSame('18|:AGE:Y', $result);
    }

    public function test_encode_bunny_age_constraint_with_only_upper_bound_set(): void
    {
        $result = $this->bunnyContext->encodeBunnyAgeConstraint(null, 65);

        $this->assertSame('|65:AGE:Y', $result);
    }
}
