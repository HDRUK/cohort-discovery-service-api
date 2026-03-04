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
        $this->enableMiddleware();

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_it_can_list_features(): void
    {
        Feature::define('test-feature-one', fn () => true);
        Feature::define('test-feature-two', fn () => false);

        $response = $this->actingAsJwt($this->user, [])
            ->getJson(self::BASE_URL);

        $response->assertStatus(200);
        $content = $response->json('data');

        $this->assertEquals(true, $content['test-feature-one']);
        $this->assertEquals(false, $content['test-feature-two']);
    }

    public function test_it_can_update_feature_status(): void
    {
        Feature::define('test-feature-three', fn () => false);

        $response = $this->actingAsJwt($this->user, [])
            ->putJson(self::BASE_URL . '/test-feature-three', [
                'enabled' => true,
            ]);

        $response->assertOk();
        $this->assertTrue(Feature::active('test-feature-three'));

        $response = $this->actingAsJwt($this->user, [])
            ->putJson(self::BASE_URL . '/test-feature-three', [
                'enabled' => false,
            ]);

        $response->assertOk();
        $this->assertFalse(Feature::active('test-feature-three'));
    }

    public function test_it_prevents_creating_new_feature(): void
    {
        $response = $this->actingAsJwt($this->user, [])
            ->postJson(self::BASE_URL, [
                'name' => 'new-feature',
                'enabled' => true,
            ]);

        $response->assertMethodNotAllowed();
    }

    public function test_non_admin_cannot_update_feature_status(): void
    {
        Feature::define('test-feature-five', fn () => false);

        $nonAdmin = User::factory()->create();

        $response = $this->actingAsJwt($nonAdmin, [])
            ->putJson(self::BASE_URL . '/test-feature-five', ['enabled' => true]);

        $response->assertStatus(403);
        $this->assertFalse(Feature::active('test-feature-five'));
    }
}
