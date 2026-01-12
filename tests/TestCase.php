<?php

namespace Tests;

use App\Models\User;
use App\Support\ApplicationMode;
use Firebase\JWT\JWT;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Traits\RefreshDatabaseLite;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabaseLite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liteSetUp();

        if (ApplicationMode::isStandalone()) {
            Config::set('api.jwt_secret', Config::get('api.jwt_secret', 'test_secret'));
        } else {
            Config::set('api.jwt_secret', Config::get('integrated.jwt_secret', 'test_secret'));
        }

        $this->disableMiddleware();
        $this->disableObservers();

        Http::fake([
            'http://localhost:5050/api/*' => Http::response([
                'responseSummary' => [
                    'exists' => true,
                    'numTotalResults' => 1000,
                ],
            ], 200),
        ]);

        Http::preventStrayRequests();
    }

    protected function disableMiddleware(): void
    {
        $this->withoutMiddleware();
    }

    protected function enableMiddleware(): void
    {
        $this->withMiddleware();
    }

    protected function disableObservers(): void
    {
        Model::unsetEventDispatcher();
    }

    protected function enableObservers(): void
    {
        Model::setEventDispatcher(app('events'));
    }

    /**
     * @param  \App\Models\User  $user
     */
    protected function makeJwtToken($user = null, array $overrides = []): string
    {
        $email = match (true) {
            $user instanceof User => $user->email,
            is_string($user) => $user,
            is_array($user) => Arr::get($user, 'email'),
            default => null,
        };

        $now = time();
        $payload = array_replace_recursive([
            'iss' => 'test-suite',
            'iat' => $now,
            'exp' => $now + 3600,
            'user' => [
                'email' => $email,
                'cohort_admin_teams' => [],
                'workgroups' => [],
            ],
        ], $overrides);

        $secret = Config::get('api.jwt_secret', 'test_secret');

        return JWT::encode($payload, $secret, 'HS256');
    }

    protected function withJwt(string $token): static
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * @param  \App\Models\User  $user
     */
    protected function actingAsJwt($user, array $overrides = []): static
    {
        $token = $this->makeJwtToken($user, $overrides);

        return $this->withJwt($token);
    }

    protected function decodeJwt(string $jwt): array
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    }

    protected function idsFromOkResponse(TestResponse $response): array
    {
        $rows = $response->assertOk()->json('data.data') ?? $response->json('data');
        return collect($rows)->pluck('id')->all();
    }
}
