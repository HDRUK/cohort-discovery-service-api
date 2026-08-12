<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/**
 * In-test OIDC identity provider: generates an RSA keypair, serves
 * discovery/JWKS/token/userinfo responses via Http::fake, and mints
 * signed id_tokens.
 */
class FakeIdp
{
    public const ISSUER = 'https://idp.test/realms/cohort-discovery-service';
    public const CLIENT_ID = 'cohort-discovery-service-api';
    public const KID = 'fake-idp-key-1';

    private static ?string $privateKey = null;
    private static ?array $publicKeyDetails = null;

    private array $tokenResponseOverrides = [];
    private ?array $userinfoResponse = null;

    public static function privateKey(): string
    {
        if (self::$privateKey === null) {
            $resource = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            openssl_pkey_export($resource, $pem);
            self::$privateKey = $pem;
            self::$publicKeyDetails = openssl_pkey_get_details($resource);
        }

        return self::$privateKey;
    }

    public static function discoveryResponse(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
            'userinfo_endpoint' => self::ISSUER.'/protocol/openid-connect/userinfo',
            'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
        ];
    }

    public static function jwksResponse(): array
    {
        self::privateKey();
        $details = self::$publicKeyDetails;

        $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => self::KID,
                    'n' => $encode($details['rsa']['n']),
                    'e' => $encode($details['rsa']['e']),
                ],
            ],
        ];
    }

    public static function mintIdToken(array $claims = [], ?string $kid = self::KID, ?string $signingKey = null): string
    {
        $now = time();

        $payload = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'fake-subject-1',
            'iat' => $now,
            'exp' => $now + 300,
        ], $claims);

        return JWT::encode($payload, $signingKey ?? self::privateKey(), 'RS256', $kid);
    }

    public function withTokenResponse(array $overrides): self
    {
        $this->tokenResponseOverrides = $overrides;

        return $this;
    }

    public function withUserinfo(array $response): self
    {
        $this->userinfoResponse = $response;

        return $this;
    }

    /**
     * Register Http::fake routes for the full provider surface. The token
     * endpoint returns an id_token built from $idTokenClaims unless the
     * response is overridden wholesale.
     */
    public function fakeHttp(array $idTokenClaims = []): void
    {
        $tokenResponse = array_merge([
            'access_token' => 'fake-idp-access-token',
            'token_type' => 'Bearer',
            'id_token' => self::mintIdToken($idTokenClaims),
        ], $this->tokenResponseOverrides);

        $fakes = [
            self::ISSUER.'/.well-known/openid-configuration' => Http::response(self::discoveryResponse()),
            self::ISSUER.'/protocol/openid-connect/certs' => Http::response(self::jwksResponse()),
            self::ISSUER.'/protocol/openid-connect/token' => Http::response($tokenResponse),
        ];

        if ($this->userinfoResponse !== null) {
            $fakes[self::ISSUER.'/protocol/openid-connect/userinfo'] = Http::response($this->userinfoResponse);
        }

        Http::fake($fakes);
    }

    /**
     * Config array for a provider entry pointing at this fake IdP.
     */
    public static function providerConfig(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'label' => 'Fake IdP',
            'issuer' => self::ISSUER,
            'discovery_url' => self::ISSUER.'/.well-known/openid-configuration',
            'client_id' => self::CLIENT_ID,
            'client_secret' => 'fake-client-secret',
            'scopes' => 'openid profile email',
            'redirect_uri' => null,
        ], $overrides);
    }
}
