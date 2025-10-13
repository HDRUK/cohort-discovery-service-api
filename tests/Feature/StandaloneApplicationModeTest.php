<?php

namespace Test\Feature;

use Tests\TestCase;
use Laravel\Passport\Client;
use App\Models\User;

class StandaloneApplicationModeTest extends TestCase
{
    private string $url = '/api/auth';

    public function setUp(): void
    {
        parent::setUp();

        // Ensure we are in standalone mode for these tests
        config(['app.mode' => 'standalone']);
    }

    public function test_the_application_can_login_standalone_users(): void
    {
        Client::truncate();

        Client::factory()->create([
            'provider' => 'users',
            'grant_types' => ['personal_access'],
            'revoked' => false,
        ]);

        $user = User::factory()->create([
            'email' => 'test@test.com',
            'name' => 'Test User',
            'password' => '$2y$12$HpqKWe/3w2u8wyLunwHInuJUiksENoVheROqXNhsjTLMqwi5Gmjxm',
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson($this->url . '/login', [
            'email' => config('integrated.test_user_email'),
            'password' => config('integrated.test_user_password'),
        ]);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertArrayHasKey('access_token', $content['data']);
        $this->assertNotEmpty($content['data']['access_token']);
    }

    public function test_the_application_can_logout_standalone_users(): void
    {
        Client::truncate();

        Client::factory()->create([
            'provider' => 'users',
            'grant_types' => ['personal_access'],
            'revoked' => false,
        ]);

        $user = User::factory()->create([
            'email' => 'test@test.com',
            'name' => 'Test User',
            'password' => '$2y$12$HpqKWe/3w2u8wyLunwHInuJUiksENoVheROqXNhsjTLMqwi5Gmjxm',
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson($this->url . '/login', [
            'email' => config('integrated.test_user_email'),
            'password' => config('integrated.test_user_password'),
        ]);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertArrayHasKey('access_token', $content['data']);
        $this->assertNotEmpty($content['data']['access_token']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $content['data']['access_token'],
        ])->postJson($this->url . '/logout');

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertEquals('logged out', $content['data']['message']);
    }
}
