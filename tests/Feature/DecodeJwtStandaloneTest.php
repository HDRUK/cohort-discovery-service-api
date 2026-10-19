<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Tests\TestCase;

class DecodeJwtStandaloneTest extends TestCase
{
    private string $url = '/api/v1/user';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();
        $this->user = User::factory()->create();
    }

    public function test_valid_rs256_token_authenticates(): void
    {
        $response = $this->actingAsJwt($this->user)->getJson($this->url);

        $response->assertOk();
    }

    public function test_expired_token_is_rejected(): void
    {
        $now = time();
        $token = $this->makeJwtToken($this->user, [
            'iat' => $now - 7200,
            'exp' => $now - 3600,
        ]);

        $response = $this->withJwt($token)->getJson($this->url);

        $response->assertUnauthorized();
    }

    public function test_token_signed_with_foreign_key_is_rejected(): void
    {
        $foreignKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($foreignKey, $foreignPem);

        $now = time();
        $payload = [
            'iss' => 'test-suite',
            'iat' => $now,
            'exp' => $now + 3600,
            'user' => ['email' => $this->user->email],
        ];

        $token = JWT::encode($payload, $foreignPem, 'RS256');

        $response = $this->withJwt($token)->getJson($this->url);

        $response->assertUnauthorized();
    }

    public function test_hs256_token_is_rejected(): void
    {
        $now = time();
        $payload = [
            'iss' => 'test-suite',
            'iat' => $now,
            'exp' => $now + 3600,
            'user' => ['email' => $this->user->email],
        ];

        $token = JWT::encode($payload, 'some_shared_secret', 'HS256');

        $response = $this->withJwt($token)->getJson($this->url);

        $response->assertUnauthorized();
    }

    public function test_garbage_token_is_rejected(): void
    {
        $response = $this->withJwt('not-a-jwt-at-all')->getJson($this->url);

        $response->assertUnauthorized();
    }

    public function test_missing_token_is_rejected(): void
    {
        $response = $this->getJson($this->url);

        $response->assertUnauthorized();
    }
}
