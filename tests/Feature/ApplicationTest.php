<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    private string $url = '/api/v1/applications';

    public function test_the_application_can_create_applications(): void
    {
        $user = User::factory()->create();

        $payload = [
            'application_name' => 'Shiny New Application',
            'redirect_uris' => ['urn:ietf:wg:oauth:2.0:oob'],
        ];

        $response = $this->actingAs($user)->post($this->url, $payload);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertArrayHasKey('client_id', $content['data']);
        $this->assertArrayHasKey('client_secret', $content['data']);

        $this->assertNotNull($content['data']['client_id']);
        $this->assertNotNull($content['data']['client_secret']);
    }

    public function test_creating_applications_requires_authentication(): void
    {
        $this->enableMiddleware();

        $response = $this->postJson($this->url, [
            'application_name' => 'Shiny New Application',
            'redirect_uris' => ['urn:ietf:wg:oauth:2.0:oob'],
        ]);

        $response->assertUnauthorized();
    }

    public function test_creating_applications_works_with_valid_jwt(): void
    {
        $this->enableMiddleware();

        $user = User::factory()->create();

        $response = $this->actingAsJwt($user)->postJson($this->url, [
            'application_name' => 'Shiny New Application',
            'redirect_uris' => ['urn:ietf:wg:oauth:2.0:oob'],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.client_id'));
    }

    public function test_creating_applications_validates_input(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson($this->url, [
            'application_name' => 'Missing URIs',
        ]);

        $response->assertUnprocessable();
    }
}
