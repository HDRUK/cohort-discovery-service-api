<?php

namespace Tests;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Tests\Traits\RefreshDatabaseLite;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabaseLite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liteSetUp();

        // Use a known secret for tests so we can mint real tokens
        Config::set('api.gateway_jwt_secret', Config::get('api.gateway_jwt_secret', 'test_secret'));

        $this->disableMiddleware(); // enable in tests that actually exercise DecodeJwt
        $this->disableObservers();
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
     * Create a signed JWT compatible with DecodeJwt middleware.
     *
     * @param  \App\Models\User|array|string|null  $user  User model, array with ['email'=>...], or raw email
     * @param  array  $overrides  Extra/override claims (merged into top-level payload)
     */
    protected function makeJwtToken($user = null, array $overrides = []): string
    {
        $email = match (true) {
            $user instanceof User => $user->email,
            is_string($user)      => $user,
            is_array($user)       => Arr::get($user, 'email'),
            default               => null,
        };

        $now = time();
        $payload = array_merge([
            'iss'  => 'test-suite',
            'iat'  => $now,
            'exp'  => $now + 3600,
            'user' => array_filter([
                'email' => $email,
                // add any other fields your app expects here, e.g. 'id' => $user->id ?? null
            ]),
        ], $overrides);

        $secret = Config::get('api.gateway_jwt_secret', 'test_secret');

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Attach a Bearer token to subsequent requests in this test.
     */
    protected function withJwt(string $token): static
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * Convenience: mint a JWT for a given user (or email) and attach it.
     *
     * @param  \App\Models\User|array|string  $user
     */
    protected function actingAsJwt($user, array $overrides = []): static
    {
        $token = $this->makeJwtToken($user, $overrides);
        return $this->withJwt($token);
    }
}
