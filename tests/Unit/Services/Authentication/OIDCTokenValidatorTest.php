<?php

namespace Tests\Unit\Services\Authentication;

use App\Models\User;
use App\Models\Workgroup;
use App\Services\Authentication\OIDCTokenValidator;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class OIDCTokenValidatorTest extends TestCase
{
    public function test_it_returns_existing_user_by_oidc_sub_only(): void
    {
        $user = User::factory()->create([
            'oidc_sub' => 'oidc-sub-1',
            'email' => 'existing@example.com',
        ]);

        $validator = $this->makeValidator(
            tokenClaims: [
                'sub' => 'oidc-sub-1',
                'email' => 'changed@example.com',
                'name' => 'Updated Name',
            ],
            userInfoPayload: [
                'sub' => 'oidc-sub-1',
                'email' => 'changed@example.com',
                'name' => 'Updated Name',
            ],
        );

        $result = $validator->validateWithClaims($this->lastToken);

        $this->assertSame($user->id, $result['user']->id);
        $this->assertDatabaseCount('users', 17);
    }

    public function test_it_does_not_match_existing_user_by_email_when_sub_differs(): void
    {
        $existingUser = User::factory()->create([
            'oidc_sub' => null,
            'email' => 'shared@example.com',
        ]);

        $validator = $this->makeValidator(
            tokenClaims: [
                'sub' => 'oidc-sub-2',
                'email' => 'shared@example.com',
                'name' => 'OIDC Person',
            ],
            userInfoPayload: [
                'sub' => 'oidc-sub-2',
                'email' => 'shared@example.com',
                'name' => 'OIDC Person',
            ],
        );

        $result = $validator->validateWithClaims($this->lastToken);

        $this->assertNotSame($existingUser->id, $result['user']->id);
        $this->assertSame('oidc-sub-2', $result['user']->oidc_sub);
        $this->assertStringEndsWith('@oidc.local', $result['user']->email);
        $this->assertDatabaseCount('users', 19);
    }

    public function test_it_provisions_and_persists_new_user_for_unknown_sub(): void
    {
        $validator = $this->makeValidator(
            tokenClaims: [
                'sub' => 'oidc-sub-3',
                'preferred_username' => 'oidc-user',
                'email_verified' => true,
            ],
            userInfoPayload: [
                'sub' => 'oidc-sub-3',
                'preferred_username' => 'oidc-user',
                'email_verified' => true,
            ],
        );

        $result = $validator->validateWithClaims($this->lastToken);

        $this->assertSame('oidc-sub-3', $result['user']->oidc_sub);
        $this->assertSame('oidc-user', $result['user']->name);
        $this->assertStringEndsWith('@oidc.local', $result['user']->email);
        $this->assertNotNull($result['user']->email_verified_at);
        $this->assertDatabaseHas('users', [
            'id' => $result['user']->id,
            'oidc_sub' => 'oidc-sub-3',
        ]);
    }

    public function test_it_throws_when_userinfo_sub_does_not_match_token_sub(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC userinfo sub does not match token sub');

        $validator = $this->makeValidator(
            tokenClaims: ['sub' => 'token-sub'],
            userInfoPayload: ['sub' => 'userinfo-sub'],
        );

        $validator->validateWithClaims($this->lastToken);
    }

    public function test_it_resolves_user_from_token_sub_when_userinfo_lacks_sub(): void
    {
        $user = User::factory()->create([
            'oidc_sub' => 'token-sub',
        ]);

        $validator = $this->makeValidator(
            tokenClaims: ['sub' => 'token-sub'],
            userInfoPayload: ['name' => 'No Sub User'],
        );

        $result = $validator->validateWithClaims($this->lastToken);

        $this->assertSame($user->id, $result['user']->id);
    }

    public function test_it_throws_when_oidc_is_enabled_without_an_audience(): void
    {
        $originalConfig = config('services.oidc');
        config([
            'services.oidc.enabled' => true,
            'services.oidc.audience' => '',
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('OIDC_AUDIENCE must be set when OIDC is enabled');

            new OIDCTokenValidator();
        } finally {
            config(['services.oidc' => $originalConfig]);
        }
    }

    public function test_it_throws_when_token_audience_does_not_match_configured_audience(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OIDC audience claim mismatch');

        $validator = $this->makeValidator(
            tokenClaims: ['aud' => 'wrong-audience'],
            userInfoPayload: ['sub' => 'oidc-sub-aud'],
        );

        $validator->validateWithClaims($this->lastToken);
    }

    public function test_it_preserves_workgroups_when_entitlement_claim_is_absent(): void
    {
        $user = User::factory()->create([
            'oidc_sub' => 'oidc-sub-wg1',
        ]);
        $workgroup = Workgroup::create([
            'name' => 'Existing Workgroup',
            'active' => true,
            'claim_value' => 'urn:wg:existing',
        ]);
        $user->workgroups()->attach($workgroup);

        $validator = $this->makeValidator(
            tokenClaims: ['sub' => 'oidc-sub-wg1'],
            userInfoPayload: ['sub' => 'oidc-sub-wg1'],
        );

        $result = $validator->validateWithClaims($this->lastToken);

        $this->assertTrue($result['user']->workgroups->contains($workgroup));
    }

    public function test_it_clears_workgroups_when_entitlement_claim_is_present_but_empty(): void
    {
        $user = User::factory()->create([
            'oidc_sub' => 'oidc-sub-wg2',
        ]);
        $workgroup = Workgroup::create([
            'name' => 'Existing Workgroup',
            'active' => true,
            'claim_value' => 'urn:wg:existing',
        ]);
        $user->workgroups()->attach($workgroup);

        $validator = $this->makeValidator(
            tokenClaims: [
                'sub' => 'oidc-sub-wg2',
                'eduperson_entitlement' => [],
            ],
            userInfoPayload: ['sub' => 'oidc-sub-wg2'],
        );

        $result = $validator->validateWithClaims($this->lastToken);

        $this->assertCount(0, $result['user']->workgroups);
    }

    public function test_it_syncs_workgroups_from_eduperson_entitlement_claim(): void
    {
        $user = User::factory()->create([
            'oidc_sub' => 'oidc-sub-wg3',
        ]);
        $matched = Workgroup::create([
            'name' => 'Matched Workgroup',
            'active' => true,
            'claim_value' => 'urn:wg:matched',
        ]);
        $unmatched = Workgroup::create([
            'name' => 'Unmatched Workgroup',
            'active' => true,
            'claim_value' => 'urn:wg:unmatched',
        ]);
        $user->workgroups()->attach($unmatched);

        $validator = $this->makeValidator(
            tokenClaims: ['sub' => 'oidc-sub-wg3'],
            userInfoPayload: [
                'sub' => 'oidc-sub-wg3',
                'eduperson_entitlement' => ['urn:wg:matched'],
            ],
        );

        $result = $validator->validateWithClaims($this->lastToken);

        $this->assertCount(1, $result['user']->workgroups);
        $this->assertTrue($result['user']->workgroups->contains($matched));
        $this->assertFalse($result['user']->workgroups->contains($unmatched));
    }

    private string $lastToken = '';

    /**
     * @param  array<string, mixed>  $tokenClaims
     * @param  array<string, mixed>  $userInfoPayload
     */
    private function makeValidator(array $tokenClaims, array $userInfoPayload): OIDCTokenValidator
    {
        $issuer = 'https://issuer-'.uniqid('', true).'.example.com';
        $jwksUri = $issuer.'/jwks';
        $userinfoEndpoint = $issuer.'/userinfo';
        $secret = 'oidc-test-shared-secret';

        config([
            'services.oidc.issuer' => $issuer,
            'services.oidc.audience' => 'cohort-api',
            'services.oidc.userinfo_endpoint' => '',
            'services.oidc.clock_skew_seconds' => 60,
            'services.oidc.discovery_cache_ttl_seconds' => 1,
            'services.oidc.jwks_cache_ttl_seconds' => 1,
        ]);

        $claims = array_replace([
            'iss' => $issuer,
            'aud' => 'cohort-api',
            'sub' => 'default-sub',
            'iat' => time() - 30,
            'exp' => time() + 3600,
        ], $tokenClaims);

        $this->lastToken = JWT::encode($claims, $secret, 'HS256', 'test-kid');

        $jwks = [
            'keys' => [[
                'kty' => 'oct',
                'kid' => 'test-kid',
                'alg' => 'HS256',
                'k' => $this->base64UrlEncode($secret),
            ]],
        ];

        $discoveryPayload = [
            'jwks_uri' => $jwksUri,
            'userinfo_endpoint' => $userinfoEndpoint,
        ];

        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')->andReturnUsing(function (string $method, string $uri, array $options = []) use ($issuer, $jwksUri, $userinfoEndpoint, $discoveryPayload, $jwks, $userInfoPayload) {
            $this->assertSame('GET', $method);

            if ($uri === rtrim($issuer, '/').'/.well-known/openid-configuration') {
                return new Response(200, ['Content-Type' => 'application/json'], json_encode($discoveryPayload));
            }

            if ($uri === $jwksUri) {
                return new Response(200, ['Content-Type' => 'application/json'], json_encode($jwks));
            }

            if ($uri === $userinfoEndpoint) {
                $this->assertSame('Bearer '.$this->lastToken, $options['headers']['Authorization'] ?? null);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($userInfoPayload));
            }

            $this->fail('Unexpected request URI: '.$uri);
        });

        return new OIDCTokenValidator($httpClient);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
