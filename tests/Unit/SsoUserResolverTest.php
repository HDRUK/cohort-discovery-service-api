<?php

namespace Tests\Unit;

use App\Exceptions\Sso\SsoLinkingException;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Sso\OidcAuthResult;
use App\Services\Sso\OidcProviderConfig;
use App\Services\Sso\SsoUserResolver;
use Laravel\Pennant\Feature;
use Tests\Support\FakeIdp;
use Tests\TestCase;

class SsoUserResolverTest extends TestCase
{
    private SsoUserResolver $resolver;

    private OidcProviderConfig $provider;

    protected function setUp(): void
    {
        parent::setUp();

        \DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        UserIdentity::truncate();
        \DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Pennant's database driver prefers stored values over define(),
        // so toggle the persisted flag directly
        Feature::activate('sso-ensure-defaults-on-jit');

        config(['sso.providers.default' => FakeIdp::providerConfig()]);

        $this->resolver = app(SsoUserResolver::class);
        $this->provider = OidcProviderConfig::fromConfig('default');
    }

    private function authResult(array $claims): OidcAuthResult
    {
        return OidcAuthResult::fromClaims(array_merge(['sub' => 'sub-1'], $claims));
    }

    public function test_jit_creates_user_with_defaults(): void
    {
        $user = $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'Fresh@Example.com',
            'email_verified' => true,
            'name' => 'Fresh User',
        ]));

        $this->assertSame('fresh@example.com', $user->email);
        $this->assertSame('Fresh User', $user->name);
        $this->assertTrue(\Hash::check('', $user->getAuthPassword()));
        $this->assertTrue($user->workgroups()->where('name', 'DEFAULT')->exists());
        $this->assertTrue($user->hasRole('user'));
        $this->assertSame(1, $user->identities()->count());
    }

    public function test_jit_defaults_are_skipped_when_flag_is_off(): void
    {
        Feature::deactivate('sso-ensure-defaults-on-jit');

        $user = $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'noflags@example.com',
            'email_verified' => true,
        ]));

        $this->assertFalse($user->workgroups()->where('name', 'DEFAULT')->exists());
        $this->assertFalse($user->hasRole('user'));
    }

    public function test_missing_email_claim_throws(): void
    {
        $this->expectException(SsoLinkingException::class);

        $this->resolver->resolve($this->provider, $this->authResult([]));
    }

    public function test_email_match_is_case_insensitive(): void
    {
        $existing = User::factory()->create(['email' => 'mixed@example.com']);

        $user = $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'MIXED@example.com',
            'email_verified' => true,
        ]));

        $this->assertSame($existing->id, $user->id);
    }

    public function test_unverified_email_cannot_link_to_existing_user(): void
    {
        User::factory()->create(['email' => 'occupied@example.com']);

        $this->expectException(SsoLinkingException::class);

        $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'occupied@example.com',
            'email_verified' => false,
        ]));
    }

    public function test_existing_identity_wins_over_email_and_updates_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'default',
            'provider_sub' => 'sub-1',
        ]);

        $resolved = $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'completely-different@example.com',
            'email_verified' => true,
            'name' => 'New Name',
        ]));

        $this->assertSame($user->id, $resolved->id);
        $this->assertSame('New Name', $resolved->fresh()->name);
        $this->assertSame(1, UserIdentity::count());
    }

    public function test_same_sub_from_different_provider_is_a_different_identity(): void
    {
        config(['sso.providers.other' => FakeIdp::providerConfig()]);
        $otherProvider = OidcProviderConfig::fromConfig('other');

        $first = $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'one@example.com',
            'email_verified' => true,
        ]));

        $second = $this->resolver->resolve($otherProvider, $this->authResult([
            'email' => 'two@example.com',
            'email_verified' => true,
        ]));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, UserIdentity::count());
    }
}
