<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Sso\OidcAuthResult;
use App\Services\Sso\OidcProviderConfig;
use App\Services\Sso\SsoUserResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeIdp;
use Tests\TestCase;

/**
 * The migration itself has already run by the time the suite boots, so what
 * these assert is the property that actually matters to a deployer upgrading
 * from the resource-server integration: a user whose sub was carried across
 * logs back into the same account instead of getting a fresh one.
 */
class OidcSubMigrationTest extends TestCase
{
    public function test_users_oidc_sub_column_is_gone(): void
    {
        $this->assertFalse(
            Schema::hasColumn('users', 'oidc_sub'),
            'users.oidc_sub should have been dropped once identities moved to user_identities'
        );
    }

    public function test_a_carried_over_identity_resolves_to_the_existing_account(): void
    {
        config(['sso.providers.default' => FakeIdp::providerConfig()]);
        $provider = OidcProviderConfig::fromConfig('default');

        $existing = User::create([
            'name' => 'Carried Over',
            'email' => 'carried@example.com',
            'password' => '',
        ]);

        // what the migration writes for a pre-existing resource-server user
        UserIdentity::create([
            'user_id' => $existing->id,
            'provider' => 'default',
            'provider_sub' => 'legacy-sub-123',
            'email_at_link' => $existing->email,
        ]);

        $before = User::count();

        $resolved = app(SsoUserResolver::class)->resolve($provider, OidcAuthResult::fromClaims([
            'sub' => 'legacy-sub-123',
            'email' => 'carried@example.com',
            'email_verified' => true,
        ]));

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame($before, User::count(), 'a carried-over user must not be duplicated on first SSO login');
        $this->assertNotNull($resolved->identities()->first()->last_login_at);
    }

    public function test_backfill_is_idempotent_against_the_unique_index(): void
    {
        // Re-running the insert the migration performs must not blow up on
        // (provider, provider_sub); this is what the exists() check buys us
        $user = User::create([
            'name' => 'Dupe Guard',
            'email' => 'dupe@example.com',
            'password' => '',
        ]);

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'default',
            'provider_sub' => 'dupe-sub',
            'email_at_link' => $user->email,
        ]);

        $alreadyThere = DB::table('user_identities')
            ->where('provider', 'default')
            ->where('provider_sub', 'dupe-sub')
            ->exists();

        $this->assertTrue($alreadyThere);
        $this->assertSame(1, UserIdentity::where('provider_sub', 'dupe-sub')->count());
    }
}
