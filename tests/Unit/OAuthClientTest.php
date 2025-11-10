<?php

namespace Tests\Unit;

use DB;
use Tests\TestCase;
use App\Models\User;
use Laravel\Passport\ClientRepository;

class OAuthClientTest extends TestCase
{
    private $uriString = 'urn:ietf:wg:oauth:2.0:oob';

    public function test_the_application_can_create_device_flow_clients(): void
    {
        User::factory(1)->create();
        $user = User::find(1)->first();

        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            user: $user,
            name: 'Test Application 1',
            redirectUris: [$this->uriString],
            confidential: true,
            enableDeviceFlow: true
        );

        $clients = $user->oauthApps()->get();

        $this->assertNotEmpty($clients);
        foreach ($clients as $c) {
            $this->assertEquals($c->name, 'Test Application 1');
            $this->assertEquals($c->owner_id, 1);
            $this->assertNotEmpty($c->id); // Client ID
            $this->assertNotEmpty($client->plainSecret); // Client Secret
        }
    }

    public function test_the_application_can_issue_device_flow_codes(): void
    {
        User::factory(1)->create();
        $user = User::find(1)->first();

        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            user: $user,
            name: 'Test Application 2',
            redirectUris: [$this->uriString],
            confidential: true,
            enableDeviceFlow: true,
        );

        $clientId = $client->id;
        $clientSecret = $client->plainSecret;

        $response = $this->postJson('/oauth/device/code', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'users:read',
        ]);

        $response->assertStatus(200);

        $content = $response->json();
        $this->assertArrayHasKey('device_code', $content);
        $this->assertArrayHasKey('user_code', $content);
        $this->assertArrayHasKey('verification_uri', $content);
        $this->assertArrayHasKey('expires_in', $content);
        $this->assertArrayHasKey('interval', $content);
    }

    public function test_the_application_can_create_public_clients(): void
    {
        DB::statement('truncate oauth_clients');

        User::factory(1)->create()->first();
        $user = User::find(1)->first();

        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            user: $user,
            name: 'Public Application 1',
            redirectUris: [$this->uriString],
            confidential: false,
            enableDeviceFlow: true
        );

        $clients = $user->oauthApps()->get();

        $this->assertNotEmpty($clients);
        foreach ($clients as $c) {
            $this->assertEquals($c->name, 'Public Application 1');
            $this->assertEquals($c->owner_id, 1);
            $this->assertNotEmpty($c->id); // Client ID
            $this->assertNull($client->plainSecret); // Client Secret
        }
    }

    public function test_a_device_client_can_request_device_code(): void
    {
        User::factory(1)->create()->first();
        $user = User::find(1)->first();

        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            user: $user,
            name: 'Public Application 2',
            redirectUris: [$this->uriString],
            confidential: true,
            enableDeviceFlow: true,
        );

        $response = $this->postJson('/oauth/device/code', [
            'client_id' => $client->id,
            'scope' => 'cohorts:query',
        ]);

        $response->assertStatus(200);

        $content = $response->json();
        $this->assertArrayHasKey('device_code', $content);
        $this->assertArrayHasKey('user_code', $content);
        $this->assertArrayHasKey('verification_uri', $content);
        $this->assertArrayHasKey('expires_in', $content);
        $this->assertArrayHasKey('interval', $content);
    }
}
