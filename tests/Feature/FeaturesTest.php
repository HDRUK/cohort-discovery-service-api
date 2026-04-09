<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class FeaturesTest extends TestCase
{
    private const BASE_URL = '/api/v1/features';
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'database');

        $this->enableObservers();
        $this->enableMiddleware();

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        DB::table('features')->truncate();
        DB::table('activity_log')->truncate();
    }

    public function test_it_can_list_features(): void
    {
        $this->assertSame('database', config('pennant.default'));

        Feature::activate('test-feature-one');
        Feature::deactivate('test-feature-two');

        $this->assertDatabaseHas('features', [
            'name' => 'test-feature-one',
            'scope' => '__laravel_null',
            'value' => 'true',
        ]);

        $this->assertDatabaseHas('features', [
            'name' => 'test-feature-two',
            'scope' => '__laravel_null',
            'value' => 'false',
        ]);

        $response = $this->actingAsJwt($this->user, [])
            ->getJson(self::BASE_URL);

        $response->assertOk();

        $content = $response->json('data');

        $this->assertSame(true, $content['test-feature-one']);
        $this->assertSame(false, $content['test-feature-two']);
    }

    public function test_it_can_update_feature_status(): void
    {
        Feature::deactivate('test-feature-three');

        $response = $this->actingAsJwt($this->user, [])
            ->putJson(self::BASE_URL . '/test-feature-three', [
                'enabled' => true,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('features', [
            'name' => 'test-feature-three',
            'scope' => '__laravel_null',
            'value' => 'true',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'feature_flags',
            'description' => 'feature_flag_updated',
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'properties->feature' => 'test-feature-three',
            'properties->before->enabled' => false,
            'properties->after->enabled' => true,
        ]);

        $response = $this->actingAsJwt($this->user, [])
            ->putJson(self::BASE_URL . '/test-feature-three', [
                'enabled' => false,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('features', [
            'name' => 'test-feature-three',
            'scope' => '__laravel_null',
            'value' => 'false',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'properties->feature' => 'test-feature-three',
            'properties->before->enabled' => true,
            'properties->after->enabled' => false,
        ]);

        $this->assertDatabaseCount('activity_log', 2);
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
        Feature::deactivate('test-feature-five');

        $nonAdmin = User::factory()->create();

        $response = $this->actingAsJwt($nonAdmin, [])
            ->putJson(self::BASE_URL . '/test-feature-five', [
                'enabled' => true,
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('features', [
            'name' => 'test-feature-five',
            'scope' => '__laravel_null',
            'value' => 'true',
        ]);

        $this->assertDatabaseCount('activity_log', 0);
    }
}
