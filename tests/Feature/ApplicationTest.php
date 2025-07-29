<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\User;

class ApplicationTest extends TestCase
{
    private string $url = '/api/applications';

    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        User::factory(1)->create();
        $user = User::find(1)->first();

        $payload = [
            'user_id' => $user->id,
            'application_name' => 'Shiny New Application',
            'redirect_uris' => ['urn:ietf:wg:oauth:2.0:oob'],
        ];

        $response = $this->post($this->url, $payload);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertArrayHasKey('client_id', $content['data']);
        $this->assertArrayHasKey('client_secret', $content['data']);

        $this->assertNotNull($content['data']['client_id']);
        $this->assertNotNull($content['data']['client_secret']);
    }
}
