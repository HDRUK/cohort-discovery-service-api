<?php

namespace Test\Feature;

use App\Models\User;
use DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;
use Tests\TestCase;

class StandaloneApplicationModeTest extends TestCase
{
    private string $url = '/api/auth';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Ensure we are in standalone mode for these tests
        config(['system.operation_mode' => 'standalone']);
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
            'email' => config('integrated.test_user_email'),
            'name' => 'Test User',
            'password' => Hash::make(config('integrated.test_user_password')),
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson($this->url.'/login', [
            'email' => config('integrated.test_user_email'),
            'password' => config('integrated.test_user_password'),
        ]);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertArrayHasKey('access_token', $content['data']);
        $this->assertNotEmpty($content['data']['access_token']);

        $token = $content['data']['access_token'];
        $payload = $this->decodeJwt($token);
        $ttlMinutes = config('system.standalone_jwt_ttl_minutes', 60);
        $expectedExp = now()->addMinutes($ttlMinutes)->timestamp;

        // Allow for a slight drift in timing
        $this->assertTrue(
            abs($payload['exp'] - $expectedExp) < 5,
            'JWT expiration time is not as expected, even with drift factor'
        );
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
            'email' => config('integrated.test_user_email'),
            'name' => 'Test User',
            'password' => Hash::make(config('integrated.test_user_password')),
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson($this->url.'/login', [
            'email' => config('integrated.test_user_email'),
            'password' => config('integrated.test_user_password'),
        ]);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertArrayHasKey('access_token', $content['data']);
        $this->assertNotEmpty($content['data']['access_token']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$content['data']['access_token'],
        ])->postJson($this->url.'/logout');

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertEquals('logged out', $content['data']['message']);
    }
}
