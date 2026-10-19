<?php

namespace Tests\Unit;

use App\Exceptions\Sso\SsoLinkingException;
use App\Models\Custodian;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Workgroup;
use App\Services\Sso\OidcAuthResult;
use App\Services\Sso\OidcProviderConfig;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\Sso\SsoUserResolver;
use Illuminate\Support\Str;
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

        config([
            'claimsaccesscontrol.sync.provision.defaults_on_create' => true,
            'claimsaccesscontrol.sync.workgroups.trust' => ClaimSyncPolicy::TRUST_NEVER,
            'claimsaccesscontrol.sync.workgroups.authoritative' => false,
            'claimsaccesscontrol.sync.roles.trust' => ClaimSyncPolicy::TRUST_NEVER,
            'claimsaccesscontrol.sync.roles.authoritative' => false,
            'sso.providers.default' => FakeIdp::providerConfig(),
        ]);

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

    public function test_jit_defaults_are_skipped_when_turned_off(): void
    {
        config(['claimsaccesscontrol.sync.provision.defaults_on_create' => false]);

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

    private function withClaimMapping(array $mapping = [], array $sync = []): OidcProviderConfig
    {
        config(['sso.providers.mapped' => FakeIdp::providerConfig([
            'claim_mapping' => array_merge([
                'workgroups_claim' => 'eduperson_entitlement',
                'roles_claim' => null,
            ], $mapping),
        ])]);

        config(array_merge([
            'claimsaccesscontrol.workgroup_mappings' => [
                'mapped-wg' => 'urn:wg:mapped',
                'local-only' => 'urn:wg:local',
            ],
            'claimsaccesscontrol.sync.workgroups.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
        ], $sync));

        return OidcProviderConfig::fromConfig('mapped');
    }

    private function custodian(string $name): Custodian
    {
        return Custodian::firstOrCreate(['name' => $name], ['pid' => (string) Str::uuid()]);
    }

    public function test_claims_are_ignored_when_they_are_never_trusted(): void
    {
        Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['active' => 1]);

        $this->withClaimMapping([], ['claimsaccesscontrol.sync.workgroups.trust' => ClaimSyncPolicy::TRUST_NEVER]);

        $user = $this->resolver->resolve($this->provider, $this->authResult([
            'email' => 'nomap@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        $this->assertFalse($user->workgroups()->where('name', 'MAPPED-WG')->exists());
    }

    public function test_claim_mapping_assigns_workgroups_from_entitlements(): void
    {
        $wg = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['active' => 1]);

        $user = $this->resolver->resolve($this->withClaimMapping([]), $this->authResult([
            'email' => 'mapped@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped', 'urn:wg:unknown'],
        ]));

        $this->assertTrue($user->workgroups()->where('workgroups.id', $wg->id)->exists());
    }

    public function test_additive_sync_never_removes_a_locally_assigned_workgroup(): void
    {
        $local = Workgroup::firstOrCreate(['name' => 'LOCAL-ONLY'], ['active' => 1]);
        $mapped = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['active' => 1]);

        $provider = $this->withClaimMapping();

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

    public function test_authoritative_sync_removes_workgroups_the_claim_omits(): void
    {
        $local = Workgroup::firstOrCreate(['name' => 'LOCAL-ONLY'], ['active' => 1]);
        $mapped = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['active' => 1]);

        $provider = $this->withClaimMapping([], ['claimsaccesscontrol.sync.workgroups.authoritative' => true]);

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
            'properties->authoritative' => true,
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

    public function test_absent_claim_leaves_workgroups_untouched_even_under_authoritative_sync(): void
    {
        $mapped = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['active' => 1]);

        $provider = $this->withClaimMapping([], ['claimsaccesscontrol.sync.workgroups.authoritative' => true]);

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

    public function test_present_but_empty_claim_clears_workgroups_under_authoritative_sync(): void
    {
        $mapped = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['active' => 1]);

        $provider = $this->withClaimMapping([], ['claimsaccesscontrol.sync.workgroups.authoritative' => true]);

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
        $wg = Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['active' => 1]);

        $user = $this->resolver->resolve($this->withClaimMapping([]), $this->authResult([
            'email' => 'jsonclaim@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => '["urn:wg:mapped"]',
        ]));

        $this->assertTrue($user->workgroups()->where('workgroups.id', $wg->id)->exists());
    }

    public function test_roles_are_mapped_via_the_role_mappings_config(): void
    {
        $provider = $this->withClaimMapping(
            ['roles_claim' => 'groups'],
            [
                'claimsaccesscontrol.role_mappings' => ['admin' => 'urn:group:cohort-admins'],
                'claimsaccesscontrol.sync.roles.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
            ],
        );

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'rolemapped@example.com',
            'email_verified' => true,
            'groups' => ['urn:group:cohort-admins', 'urn:group:unmapped'],
        ]));

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_custodians_are_matched_to_existing_records_by_name(): void
    {
        $custodian = Custodian::factory()->create(['name' => 'Acme Health']);

        $provider = $this->withClaimMapping(
            ['custodians_claim' => 'teams'],
            ['claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_ALWAYS],
        );

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'custodian-match@example.com',
            'email_verified' => true,
            'teams' => ['acme health', 'unknown-team'],
        ]));

        $custodians = $user->fresh()->custodians;
        $this->assertCount(1, $custodians);
        $this->assertTrue($custodians->contains('id', $custodian->id));
    }

    public function test_custodians_are_not_created_from_unmatched_claim_values(): void
    {
        $provider = $this->withClaimMapping(
            ['custodians_claim' => 'teams'],
            ['claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_ALWAYS],
        );

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'custodian-unmatched@example.com',
            'email_verified' => true,
            'teams' => ['nonexistent-team'],
        ]));

        $this->assertFalse(Custodian::where('name', 'nonexistent-team')->exists());
        $this->assertCount(0, $user->fresh()->custodians);
    }

    public function test_authoritative_sync_detaches_a_custodian_the_claim_omits(): void
    {
        $acme = $this->custodian('Detach Acme');
        $beta = $this->custodian('Detach Beta');

        $provider = $this->withClaimMapping(
            ['custodians_claim' => 'teams'],
            [
                'claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
                'claimsaccesscontrol.sync.custodians.authoritative' => true,
            ],
        );

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'custodian-detach@example.com',
            'email_verified' => true,
            'teams' => ['detach acme', 'detach beta'],
        ]));
        $this->assertCount(2, $user->fresh()->custodians);

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'custodian-detach@example.com',
            'email_verified' => true,
            'teams' => ['detach acme'],
        ]));

        $custodians = $user->fresh()->custodians;
        $this->assertTrue($custodians->contains('id', $acme->id));
        $this->assertFalse($custodians->contains('id', $beta->id));
    }

    public function test_absent_custodians_claim_leaves_custodians_untouched_under_authoritative_sync(): void
    {
        $acme = $this->custodian('Silent Acme');

        $provider = $this->withClaimMapping(
            ['custodians_claim' => 'teams'],
            [
                'claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
                'claimsaccesscontrol.sync.custodians.authoritative' => true,
            ],
        );

        $this->resolver->resolve($provider, $this->authResult([
            'email' => 'custodian-silent@example.com',
            'email_verified' => true,
            'teams' => ['silent acme'],
        ]));

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'custodian-silent@example.com',
            'email_verified' => true,
        ]));

        $this->assertTrue($user->fresh()->custodians->contains('id', $acme->id));
    }

    public function test_present_but_empty_custodians_claim_clears_custodians_under_authoritative_sync(): void
    {
        $acme = $this->custodian('Emptied Acme');

        $provider = $this->withClaimMapping(
            ['custodians_claim' => 'teams'],
            [
                'claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
                'claimsaccesscontrol.sync.custodians.authoritative' => true,
            ],
        );

        $this->resolver->resolve($provider, $this->authResult([
            'email' => 'custodian-emptied@example.com',
            'email_verified' => true,
            'teams' => ['emptied acme'],
        ]));

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'custodian-emptied@example.com',
            'email_verified' => true,
            'teams' => [],
        ]));

        $this->assertFalse($user->fresh()->custodians->contains('id', $acme->id));
    }

    public function test_the_default_workgroup_floor_survives_an_authoritative_clear(): void
    {
        $default = Workgroup::firstOrCreate(['name' => 'DEFAULT'], ['active' => 1]);
        Workgroup::firstOrCreate(['name' => 'MAPPED-WG'], ['active' => 1]);

        $provider = $this->withClaimMapping([], [
            'claimsaccesscontrol.sync.workgroups.authoritative' => true,
            'claimsaccesscontrol.sync.workgroups.ensure_default' => true,
        ]);

        $this->resolver->resolve($provider, $this->authResult([
            'email' => 'floored@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => ['urn:wg:mapped'],
        ]));

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'floored@example.com',
            'email_verified' => true,
            'eduperson_entitlement' => [],
        ]));

        $this->assertSame(
            ['DEFAULT'],
            $user->fresh()->workgroups()->pluck('name')->all()
        );
        $this->assertTrue($user->workgroups()->where('workgroups.id', $default->id)->exists());
    }

    public function test_the_default_role_floor_survives_an_authoritative_clear(): void
    {
        $provider = $this->withClaimMapping(
            ['roles_claim' => 'groups'],
            [
                'claimsaccesscontrol.sync.roles.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
                'claimsaccesscontrol.sync.roles.authoritative' => true,
                'claimsaccesscontrol.sync.roles.ensure_default' => true,
                'claimsaccesscontrol.role_mappings' => ['admin' => 'urn:group:cohort-admins'],
            ],
        );

        $user = $this->resolver->resolve($provider, $this->authResult([
            'email' => 'role-floored@example.com',
            'email_verified' => true,
            'groups' => [],
        ]));

        $this->assertSame(['user'], $user->fresh()->roles()->pluck('name')->all());
    }

    public function test_unknown_trust_value_falls_back_to_never(): void
    {
        $local = Workgroup::firstOrCreate(['name' => 'LOCAL-ONLY'], ['active' => 1]);

        $provider = $this->withClaimMapping([], ['claimsaccesscontrol.sync.workgroups.trust' => 'nonsense']);

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
