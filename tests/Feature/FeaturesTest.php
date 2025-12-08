<?php

namespace Tests\Feature;

use Tests\TestCase;
use Laravel\Pennant\Feature;
use App\Models\User;

class FeaturesTest extends TestCase
{
    private const BASE_URL = '/api/v1/features';
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableObservers();

        $this->user = User::factory()->create();
    }

    public function test_it_can_list_features(): void
    {
        Feature::define('test-feature-one', fn () => true);
        Feature::define('test-feature-two', fn () => false);

        $this->enableMiddleware();

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'admin',
                ]],
            ],
        ];

        $response = $this->actingAsJwt($this->user, $overrides)
            ->getJson(self::BASE_URL);

        $response->assertStatus(200);
        $content = $response->json('data');

        $this->assertEquals(true, $content['test-feature-one']);
        $this->assertEquals(false, $content['test-feature-two']);
    }

    public function test_it_can_update_feature_status(): void
    {
        Feature::define('test-feature-three', fn () => false);

        $this->enableMiddleware();


        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'admin',
                ]],
            ],
        ];

        $response = $this->actingAsJwt($this->user, $overrides)
            ->putJson(self::BASE_URL . '/test-feature-three', [
                'enabled' => true,
            ]);

        $response->assertOk();
        $this->assertTrue(Feature::active('test-feature-three'));

        $response = $this->actingAsJwt($this->user, $overrides)
            ->putJson(self::BASE_URL . '/test-feature-three', [
                'enabled' => false,
            ]);

        $response->assertOk();
        $this->assertFalse(Feature::active('test-feature-three'));
    }

    public function test_it_prevents_creating_new_feature(): void
    {
        $this->enableMiddleware();

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'admin',
                ]],
            ],
        ];

        $response = $this->actingAsJwt($this->user, $overrides)
            ->postJson(self::BASE_URL, [
                'name' => 'new-feature',
                'enabled' => true,
            ]);

        $response->assertMethodNotAllowed();
    }

    public function test_it_prevents_non_admin_access(): void
    {
        $this->enableMiddleware();

        $response = $this->actingAsJwt($this->user)
            ->getJson(self::BASE_URL);

        $response->assertStatus(401);
    }
}
