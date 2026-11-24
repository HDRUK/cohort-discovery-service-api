<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'production',
            'horizon.secret' => 'correct-horse-battery-staple',
        ]);
    }

    private function viewHorizonWithKey(?string $key): bool
    {
        $query = $key === null ? [] : ['key' => $key];
        app()->instance('request', Request::create('/horizon', 'GET', $query));

        return Gate::forUser(null)->allows('viewHorizon');
    }

    public function test_no_key_is_denied(): void
    {
        $this->assertFalse($this->viewHorizonWithKey(null));
    }

    public function test_wrong_key_is_denied(): void
    {
        $this->assertFalse($this->viewHorizonWithKey('wrong'));
    }

    public function test_correct_key_is_allowed(): void
    {
        $this->assertTrue($this->viewHorizonWithKey('correct-horse-battery-staple'));
    }

    public function test_dashboard_api_calls_are_allowed_after_a_correct_key_without_repeating_it(): void
    {
        $this->assertTrue($this->viewHorizonWithKey('correct-horse-battery-staple'));

        $this->assertTrue($this->viewHorizonWithKey(null));
    }

    public function test_a_wrong_key_does_not_grant_access_to_later_calls(): void
    {
        $this->assertFalse($this->viewHorizonWithKey('wrong'));

        $this->assertFalse($this->viewHorizonWithKey(null));
    }

    public function test_unsetting_the_secret_denies_even_a_previously_authorised_session(): void
    {
        $this->assertTrue($this->viewHorizonWithKey('correct-horse-battery-staple'));

        config(['horizon.secret' => '']);

        $this->assertFalse($this->viewHorizonWithKey(null));
    }
}
