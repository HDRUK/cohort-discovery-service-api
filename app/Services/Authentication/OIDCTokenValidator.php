<?php

namespace App\Services\Authentication;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JsonException;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;

class OIDCTokenValidator
{
    private const DISCOVERY_CACHE_PREFIX = 'oidc:discovery:v1:';
    private const JWKS_CACHE_PREFIX = 'oidc:jwks:v1:';
    private const DEFAULT_DISCOVERY_TTL_SECONDS = 300;
    private const DEFAULT_JWKS_TTL_SECONDS = 300;
    private const DEFAULT_CLOCK_SKEW_SECONDS = 60;

    private readonly ClientInterface $httpClient;

    public function __construct(?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => (float) config('services.oidc.http_timeout_seconds', 10),
            'connect_timeout' => (float) config('services.oidc.connect_timeout_seconds', 3),
        ]);
    }

    /**
     * @return array{user: User, claims: array<string, mixed>}
     */
    public function validateWithClaims(string $token): array
    {
        $claims = $this->decodeAndValidateToken($token);
        $userInfo = $this->fetchUserInfo($token);

        $oidcSub = $this->resolveOidcSub($claims, $userInfo);
        $user = User::where('oidc_sub', $oidcSub)->first()
            ?? $this->provisionUserFromOidc($oidcSub, $userInfo, $claims);

        return [
            'user' => $user,
            'claims' => $claims,
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $userInfo
     */
    private function resolveOidcSub(array $claims, array $userInfo): string
    {
        $oidcSub = $this->extractStringClaim($userInfo, 'sub') ?? $this->extractStringClaim($claims, 'sub');
        if ($oidcSub === null) {
            throw new \RuntimeException('OIDC token/userinfo missing sub claim');
        }

        return $oidcSub;
    }

    /**
     * @param  array<string, mixed>  $userInfo
     * @param  array<string, mixed>  $claims
     */
    private function provisionUserFromOidc(string $oidcSub, array $userInfo, array $claims): User
    {
        $email = $this->extractStringClaim($userInfo, 'email')
            ?? $this->extractStringClaim($claims, 'email');
        $email = $email !== null ? strtolower($email) : null;
        $email = $this->resolveProvisioningEmail($email, $oidcSub);

        $name = $this->extractStringClaim($userInfo, 'name')
            ?? $this->extractStringClaim($claims, 'name')
            ?? $this->extractStringClaim($userInfo, 'preferred_username')
            ?? $this->extractStringClaim($claims, 'preferred_username')
            ?? 'OIDC User';

        $emailVerified = $this->extractBoolClaim($userInfo, 'email_verified')
            ?? $this->extractBoolClaim($claims, 'email_verified');

        $user = User::create([
            'oidc_sub' => $oidcSub,
            'name' => $name,
            'email' => $email,
            'password' => Str::random(64),
        ]);

        if ($emailVerified) {
            $user->email_verified_at = Carbon::now();
            $user->save();
        }

        return $user;
    }

    private function resolveProvisioningEmail(?string $email, string $oidcSub): string
    {
        if ($email !== null && ! User::where('email', $email)->exists()) {
            return $email;
        }

        $base = 'oidc-'.substr(sha1($oidcSub), 0, 16);
        $candidate = $base.'@oidc.local';
        $counter = 1;

        while (User::where('email', $candidate)->exists()) {
            $candidate = sprintf('%s-%d@oidc.local', $base, $counter);
            $counter++;
        }

        return $candidate;
    }

    public function validate(string $token): User
    {
        return $this->validateWithClaims($token)['user'];
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeAndValidateToken(string $token): array
    {
        $issuer = $this->configuredIssuer();
        if ($issuer === '') {
            throw new \RuntimeException('OIDC issuer is not configured');
        }

        $discovery = $this->getDiscoveryDocument($issuer);
        $jwks = $this->getJwks($discovery);
        $clockSkewSeconds = $this->clockSkewSeconds();

        // Signature verification using provider JWKS.
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $clockSkewSeconds;

        try {
            JWT::decode($token, JWK::parseKeySet($jwks));
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $parsedToken = (new Parser(new JoseEncoder()))->parse($token);
        $claims = $parsedToken->claims()->all();

        $this->validateClaims($claims, $issuer);

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserInfo(string $token): array
    {
        $issuer = $this->configuredIssuer();
        $discovery = $this->getDiscoveryDocument($issuer);

        $userInfoEndpoint = $this->resolveUserInfoEndpoint($discovery);
        $options = $this->buildUserInfoRequestOptions($token);

        return $this->requestJson('GET', $userInfoEndpoint, $options, 'OIDC userinfo');
    }

    /**
     * @param  array<string, mixed>  $discovery
     */
    private function resolveUserInfoEndpoint(array $discovery): string
    {
        $userInfoEndpoint = trim((string) config('services.oidc.userinfo_endpoint', ''));
        if ($userInfoEndpoint === '') {
            $userInfoEndpoint = (string) ($discovery['userinfo_endpoint'] ?? '');
        }

        if ($userInfoEndpoint === '') {
            throw new \RuntimeException('OIDC userinfo endpoint is not configured');
        }

        return $userInfoEndpoint;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUserInfoRequestOptions(string $token): array
    {
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ];

        $clientId = trim((string) config('services.oidc.client_id'));
        $clientSecret = trim((string) config('services.oidc.client_secret'));

        if ($clientId !== '' && $clientSecret !== '') {
            $options['auth'] = [$clientId, $clientSecret];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function getDiscoveryDocument(string $issuer): array
    {
        $cacheKey = self::DISCOVERY_CACHE_PREFIX.md5($issuer);
        $ttl = max(1, (int) config('services.oidc.discovery_cache_ttl_seconds', self::DEFAULT_DISCOVERY_TTL_SECONDS));

        /** @var array<string, mixed> $discovery */
        $discovery = Cache::remember($cacheKey, $ttl, function () use ($issuer) {
            $url = rtrim($issuer, '/').'/.well-known/openid-configuration';

            return $this->requestJson('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
            ], 'OIDC discovery');
        });

        return $discovery;
    }

    /**
     * @param  array<string, mixed>  $discovery
     * @return array<string, mixed>
     */
    private function getJwks(array $discovery): array
    {
        $jwksUri = $discovery['jwks_uri'] ?? null;
        if (! is_string($jwksUri) || $jwksUri === '') {
            throw new \RuntimeException('OIDC provider is missing jwks_uri');
        }

        $cacheKey = self::JWKS_CACHE_PREFIX.md5($jwksUri);
        $ttl = max(1, (int) config('services.oidc.jwks_cache_ttl_seconds', self::DEFAULT_JWKS_TTL_SECONDS));

        /** @var array<string, mixed> $jwks */
        $jwks = Cache::remember($cacheKey, $ttl, function () use ($jwksUri) {
            $payload = $this->requestJson('GET', $jwksUri, [
                'headers' => ['Accept' => 'application/json'],
            ], 'OIDC JWKS');

            if (! is_array($payload) || ! isset($payload['keys']) || ! is_array($payload['keys'])) {
                throw new \RuntimeException('OIDC JWKS payload is invalid');
            }

            return $payload;
        });

        return $jwks;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validateClaims(array $claims, string $issuer): void
    {
        $this->assertIssuerClaim($claims, $issuer);
        $this->assertAudienceClaim($claims);
        $this->assertNotExpired($claims);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertIssuerClaim(array $claims, string $issuer): void
    {
        $tokenIssuer = $this->extractStringClaim($claims, 'iss');
        if ($tokenIssuer === null || rtrim($tokenIssuer, '/') !== rtrim($issuer, '/')) {
            throw new \RuntimeException('OIDC issuer claim mismatch');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertAudienceClaim(array $claims): void
    {
        $configuredAudience = trim((string) config('services.oidc.audience', ''));
        $expectedAudiences = array_values(array_filter(array_map('trim', explode(',', $configuredAudience))));

        if ($expectedAudiences !== []) {
            $audClaim = $claims['aud'] ?? null;
            $audiences = is_array($audClaim) ? $audClaim : [$audClaim];

            $matched = false;
            foreach ($expectedAudiences as $expectedAudience) {
                if (in_array($expectedAudience, $audiences, true)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                throw new \RuntimeException('OIDC audience claim mismatch');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertNotExpired(array $claims): void
    {
        $clockSkewSeconds = $this->clockSkewSeconds();
        $exp = $claims['exp'] ?? null;

        if ($exp instanceof \DateTimeInterface) {
            $expTimestamp = $exp->getTimestamp();
        } elseif (is_numeric($exp)) {
            $expTimestamp = (int) $exp;
        } else {
            throw new \RuntimeException('OIDC token is expired');
        }

        if ($expTimestamp <= (time() - $clockSkewSeconds)) {
            throw new \RuntimeException('OIDC token is expired');
        }
    }

    private function configuredIssuer(): string
    {
        return trim((string) config('services.oidc.issuer'));
    }

    private function clockSkewSeconds(): int
    {
        return max(0, (int) config('services.oidc.clock_skew_seconds', self::DEFAULT_CLOCK_SKEW_SECONDS));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $options, string $context): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(sprintf('Failed to fetch %s: %s', $context, $e->getMessage()), 0, $e);
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException(sprintf('%s payload is invalid', $context), 0, $e);
        }

        if (! is_array($payload)) {
            throw new \RuntimeException(sprintf('%s payload is invalid', $context));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function extractStringClaim(array $claims, string $key): ?string
    {
        $value = $claims[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function extractBoolClaim(array $claims, string $key): ?bool
    {
        $value = $claims[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        return null;
    }
}

