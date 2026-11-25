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

        Feature::activate('in-app-messenger');
        Feature::deactivate('access-banner');

        $this->assertDatabaseHas('features', [
            'name' => 'in-app-messenger',
            'scope' => '__laravel_null',
            'value' => 'true',
        ]);

        $this->assertDatabaseHas('features', [
            'name' => 'access-banner',
            'scope' => '__laravel_null',
            'value' => 'false',
        ]);

        $response = $this->actingAsJwt($this->user, [])
            ->getJson(self::BASE_URL);

        $response->assertOk();

        $content = $response->json('data');

        $this->assertSame(true, $content['in-app-messenger']);
        $this->assertSame(false, $content['access-banner']);
    }

    public function test_it_can_update_feature_status(): void
    {
        Feature::deactivate('query-builder-use-race');

        $response = $this->actingAsJwt($this->user, [])
            ->putJson(self::BASE_URL . '/query-builder-use-race', [
                'enabled' => true,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('features', [
            'name' => 'query-builder-use-race',
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
            'properties->feature' => 'query-builder-use-race',
            'properties->before->enabled' => false,
            'properties->after->enabled' => true,
        ]);

        $response = $this->actingAsJwt($this->user, [])
            ->putJson(self::BASE_URL . '/query-builder-use-race', [
                'enabled' => false,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('features', [
            'name' => 'query-builder-use-race',
            'scope' => '__laravel_null',
            'value' => 'false',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'properties->feature' => 'query-builder-use-race',
            'properties->before->enabled' => true,
            'properties->after->enabled' => false,
        ]);

        $this->assertDatabaseCount('activity_log', 2);
    }

    public function test_updating_an_unknown_flag_is_not_found(): void
    {
        $this->actingAsJwt($this->user, [])
            ->putJson(self::BASE_URL . '/unknown-flag', [
                'enabled' => true,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('features', ['name' => 'unknown-flag']);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_list_returns_the_callers_effective_values(): void
    {
        Feature::deactivate('query-builder-use-location');

        $member = User::factory()->create();
        Feature::for($member)->activate('query-builder-use-location');

        $this->actingAsJwt($member, [])
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonPath('data.query-builder-use-location', true);

        $this->actingAsJwt($this->user, [])
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonPath('data.query-builder-use-location', false);
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
        Feature::deactivate('query-builder-use-value-as-number');

        $nonAdmin = User::factory()->create();

        $response = $this->actingAsJwt($nonAdmin, [])
            ->putJson(self::BASE_URL . '/query-builder-use-value-as-number', [
                'enabled' => true,
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('features', [
            'name' => 'query-builder-use-value-as-number',
            'scope' => '__laravel_null',
            'value' => 'true',
        ]);

        $this->assertDatabaseCount('activity_log', 0);
    }
}
