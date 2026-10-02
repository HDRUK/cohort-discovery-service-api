<?php

namespace Tests\Unit;

use App\Exceptions\Sso\SsoLinkingException;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Workgroup;
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

    private function withClaimMapping(array $mapping): OidcProviderConfig
    {
        config(['sso.providers.mapped' => FakeIdp::providerConfig([
            'claim_mapping' => array_merge([
                'enabled' => true,
                'authority' => 'local',
                'workgroups_claim' => 'eduperson_entitlement',
                'roles_claim' => null,
                'role_map' => [],
            ], $mapping),
        ])]);

        return OidcProviderConfig::fromConfig('mapped');
    }

    public function test_claim_mapping_is_off_by_default(): void
    {
        Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['claim_value' => 'urn:wg:mapped', 'active' => 1]);

        $user = $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'nomap@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        $this->assertFalse($user->workgroups()->where('name', 'MAPPED-WG')->exists());
    }

    public function test_claim_mapping_assigns_workgroups_from_entitlements(): void
    {
        $wg = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['claim_value' => 'urn:wg:mapped', 'active' => 1]);

        $user = $this->resolver->resolve($this->withClaimMapping([]), $this->authResult([
            'email' => 'mapped@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped', 'urn:wg:unknown'],
        ]));

        $this->assertTrue($user->workgroups()->where('workgroups.id', $wg->id)->exists());
    }

    public function test_local_authority_never_removes_a_locally_assigned_workgroup(): void
    {
        $local = Workgroup::firstOrCreate(['name' => 'LOCAL-ONLY'], ['claim_value' => 'urn:wg:local', 'active' => 1]);
        $mapped = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['claim_value' => 'urn:wg:mapped', 'active' => 1]);

        $provider = $this->withClaimMapping(['authority' => 'local']);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'sticky@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        // an admin adds a workgroup by hand after first login
        $user->workgroups()->syncWithoutDetaching([$local->id]);

        // ...and the user logs in again with the IdP still silent about it
        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'sticky@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        $this->assertTrue($user->workgroups()->where('workgroups.id', $local->id)->exists());
        $this->assertTrue($user->workgroups()->where('workgroups.id', $mapped->id)->exists());
    }

    public function test_idp_authority_removes_workgroups_the_claim_omits(): void
    {
        $local = Workgroup::firstOrCreate(['name' => 'LOCAL-ONLY'], ['claim_value' => 'urn:wg:local', 'active' => 1]);
        $mapped = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['claim_value' => 'urn:wg:mapped', 'active' => 1]);

        $provider = $this->withClaimMapping(['authority' => 'idp']);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'strict@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        $user->workgroups()->syncWithoutDetaching([$local->id]);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'strict@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        $this->assertFalse($user->workgroups()->where('workgroups.id', $local->id)->exists());
        $this->assertTrue($user->workgroups()->where('workgroups.id', $mapped->id)->exists());

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sso',
            'description' => 'sso_claims_mapped',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'properties->relation' => 'workgroups',
            'properties->authority' => 'idp',
            'properties->detached' => json_encode([$local->id]),
        ]);
    }

    public function test_provisioning_and_linking_are_written_to_the_activity_log(): void
    {
        \DB::table('activity_log')->truncate();

        $provisioned = $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'resolver.audited@example.com',
            'email_verified' => true,
        ]));

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sso',
            'description' => 'sso_user_provisioned',
            'subject_type' => User::class,
            'subject_id' => $provisioned->id,
            'properties->provider' => 'default',
        ]);

        $existing = User::factory()->create(['email' => 'already.here@example.com']);

        $this->resolver->resolve($this->provider, OidcAuthResult::fromClaims([
            'sub' => 'sub-2',
            'email' => $existing->email,
            'email_verified' => true,
        ]));

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sso',
            'description' => 'sso_identity_linked',
            'subject_type' => User::class,
            'subject_id' => $existing->id,
            'properties->matched_on' => 'verified_email',
        ]);
    }

    public function test_absent_claim_leaves_workgroups_untouched_even_under_idp_authority(): void
    {
        $mapped = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['claim_value' => 'urn:wg:mapped', 'active' => 1]);

        $provider = $this->withClaimMapping(['authority' => 'idp']);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'quiet@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        // IdP stops releasing the claim entirely - that is silence, not "none"
        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'quiet@example.com',
            'email_verified' => true,
        ]));

        $this->assertTrue($user->workgroups()->where('workgroups.id', $mapped->id)->exists());
    }

    public function test_present_but_empty_claim_clears_workgroups_under_idp_authority(): void
    {
        $mapped = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['claim_value' => 'urn:wg:mapped', 'active' => 1]);

        $provider = $this->withClaimMapping(['authority' => 'idp']);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'emptied@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'emptied@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => [],
        ]));

        $this->assertFalse($user->workgroups()->where('workgroups.id', $mapped->id)->exists());
    }

    public function test_entitlements_encoded_as_a_json_string_are_understood(): void
    {
        $wg = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['claim_value' => 'urn:wg:mapped', 'active' => 1]);

        $user = $this->resolver->resolve($this->withClaimMapping([]), $this->authResult([
            'email' => 'jsonclaim@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => '["urn:wg:mapped"]',
        ]));

        $this->assertTrue($user->workgroups()->where('workgroups.id', $wg->id)->exists());
    }

    public function test_roles_are_mapped_only_via_an_explicit_role_map(): void
    {
        $provider = $this->withClaimMapping([
            'roles_claim' => 'groups',
            'role_map' => ['urn:group:cohort-admins' => 'admin'],
        ]);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'rolemapped@example.com',
            'email_verified' => true,
            'groups' => ['urn:group:cohort-admins', 'urn:group:unmapped'],
        ]));

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_unknown_authority_value_falls_back_to_local(): void
    {
        $local = Workgroup::firstOrCreate(['name' => 'LOCAL-ONLY'], ['claim_value' => 'urn:wg:local', 'active' => 1]);

        $provider = $this->withClaimMapping(['authority' => 'nonsense']);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'typo@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => [],
        ]));

        $user->workgroups()->syncWithoutDetaching([$local->id]);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'typo@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => [],
        ]));

        $this->assertTrue($user->workgroups()->where('workgroups.id', $local->id)->exists());
    }
}
