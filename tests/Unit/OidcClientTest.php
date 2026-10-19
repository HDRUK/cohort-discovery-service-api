<?php

namespace Tests\Unit;

use App\Exceptions\Sso\IdTokenValidationException;
use App\Services\Sso\OidcClient;
use App\Services\Sso\OidcProviderConfig;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeIdp;
use Tests\TestCase;

class OidcClientTest extends TestCase
{
    private OidcClient $client;

    private OidcProviderConfig $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sso.providers.default' => FakeIdp::providerConfig()]);

        $this->client = app(OidcClient::class);
        $this->provider = OidcProviderConfig::fromConfig('default');

        // The test suite uses a persistent cache store, so drop any
        // discovery/JWKS entries left behind by earlier tests
        app(\App\Services\Sso\OidcDiscoveryService::class)->forget($this->provider);
    }

    public function test_expired_token_within_leeway_is_accepted(): void
    {
        (new FakeIdp())->fakeHttp();
        config(['sso.id_token_leeway_seconds' => 30]);

        $token = FakeIdp::mintIdToken([
            'exp' => time() - 10,
            'nonce' => 'expected-nonce',
        ]);

        $claims = $this->client->validateIdToken($token, $this->provider, 'expected-nonce');

        $this->assertSame('fake-subject-1', $claims['sub']);
    }

    public function test_expired_token_beyond_leeway_is_rejected(): void
    {
        (new FakeIdp())->fakeHttp();
        config(['sso.id_token_leeway_seconds' => 30]);

        $token = FakeIdp::mintIdToken([
            'exp' => time() - 120,
            'nonce' => 'expected-nonce',
        ]);

        $this->expectException(IdTokenValidationException::class);

        $this->client->validateIdToken($token, $this->provider, 'expected-nonce');
    }

    public function test_azp_mismatch_is_rejected(): void
    {
        (new FakeIdp())->fakeHttp();

        $token = FakeIdp::mintIdToken([
            'azp' => 'another-client',
            'nonce' => 'expected-nonce',
        ]);

        $this->expectException(IdTokenValidationException::class);
        $this->expectExceptionMessage('authorised party');

        $this->client->validateIdToken($token, $this->provider, 'expected-nonce');
    }

    public function test_audience_array_containing_client_id_is_accepted(): void
    {
        (new FakeIdp())->fakeHttp();

        $token = FakeIdp::mintIdToken([
            'aud' => [FakeIdp::CLIENT_ID, 'another-audience'],
            'azp' => FakeIdp::CLIENT_ID,
            'nonce' => 'expected-nonce',
        ]);

        $claims = $this->client->validateIdToken($token, $this->provider, 'expected-nonce');

        $this->assertSame('fake-subject-1', $claims['sub']);
    }

    public function test_key_rotation_triggers_jwks_refetch(): void
    {
        // First JWKS response has an unknown kid; the refetch after the
        // cache bust returns the real key.
        $staleJwks = FakeIdp::jwksResponse();
        $staleJwks['keys'][0]['kid'] = 'stale-kid';

        Http::fake([
            FakeIdp::ISSUER.'/.well-known/openid-configuration' => Http::response(FakeIdp::discoveryResponse()),
            FakeIdp::ISSUER.'/protocol/openid-connect/certs' => Http::sequence()
                ->push($staleJwks)
                ->push(FakeIdp::jwksResponse()),
        ]);

        $token = FakeIdp::mintIdToken(['nonce' => 'expected-nonce']);

        $claims = $this->client->validateIdToken($token, $this->provider, 'expected-nonce');

        $this->assertSame('fake-subject-1', $claims['sub']);
        Http::assertSentCount(4); // discovery + stale jwks, discovery + fresh jwks
    }
}
