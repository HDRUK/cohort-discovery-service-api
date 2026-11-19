<?php

namespace Tests\Unit\Claims;

use App\Enums\ClaimTrust;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Workgroup;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\Claims\MembershipSources;
use Tests\Support\FakeIdp;
use Tests\TestCase;

class MembershipSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        UserIdentity::truncate();
        \DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        foreach (['DEFAULT', 'NHS-SDE', 'UK-INDUSTRY', 'UK-RESEARCH'] as $name) {
            Workgroup::updateOrCreate(['name' => $name], ['active' => 1]);
        }

        $this->standaloneWithSso([
            'default' => $this->provider('Single Sign-On', rolesClaim: 'roles'),
        ]);

        $this->trust(ClaimSyncPolicy::SUBJECT_WORKGROUPS, ClaimTrust::FIRST_LOGIN->value, false);
        $this->trust(ClaimSyncPolicy::SUBJECT_ROLES, ClaimTrust::ALWAYS->value, true);
        $this->trust(ClaimSyncPolicy::SUBJECT_CUSTODIANS, ClaimTrust::NEVER->value, false);

        config([
            'claimsaccesscontrol.sync.workgroups.ensure_default' => true,
            'claimsaccesscontrol.sync.workgroups.sde_from_claim' => true,
            'claimsaccesscontrol.sync.roles.ensure_default' => true,
        ]);
    }

    private function provider(
        string $label,
        ?string $rolesClaim = null,
        ?string $custodiansClaim = null,
        array $overrides = [],
    ): array {
        return FakeIdp::providerConfig(array_merge([
            'label' => $label,
            'claim_mapping' => [
                'workgroups_claim' => 'groups',
                'roles_claim' => $rolesClaim,
                'custodians_claim' => $custodiansClaim,
            ],
        ], $overrides));
    }

    private function standaloneWithSso(array $providers): void
    {
        config([
            'system.operation_mode' => 'standalone',
            'sso.enabled' => true,
            'sso.frontend_callback_url' => 'http://fe.test/auth/sso/callback',
            'sso.providers' => $providers,
        ]);
    }

    private function trust(string $subject, string $trust, bool $authoritative): void
    {
        config([
            "claimsaccesscontrol.sync.{$subject}.trust" => $trust,
            "claimsaccesscontrol.sync.{$subject}.authoritative" => $authoritative,
        ]);
    }

    private function userWithIdentities(string ...$providers): User
    {
        $user = User::factory()->create();

        foreach ($providers as $index => $provider) {
            UserIdentity::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_sub' => "subject-{$user->id}-{$index}",
                'email_at_link' => $user->email,
            ]);
        }

        return $user->load('identities');
    }

    private function forUser(User $user): array
    {
        return app(MembershipSources::class)->forUser($user);
    }

    private function deployment(): array
    {
        return app(MembershipSources::class)->deployment();
    }

    private function restoredNames(string $subject): array
    {
        return array_column($this->deployment()[$subject]['restored_on_sync'], 'name');
    }

    public function test_a_password_only_user_is_internal_even_where_sso_users_are_authoritative(): void
    {
        $sources = $this->forUser($this->userWithIdentities());

        foreach (ClaimSyncPolicy::SUBJECTS as $subject) {
            $this->assertSame(
                ['mode' => 'internal', 'editable' => true, 'sources' => []],
                $sources[$subject],
                $subject
            );
        }
    }

    public function test_a_user_of_a_provider_that_does_not_map_roles_is_internal_for_roles(): void
    {
        $this->standaloneWithSso([
            'a' => $this->provider('Provider A', rolesClaim: 'roles'),
            'b' => $this->provider('Provider B'),
        ]);

        $this->assertSame('internal', $this->forUser($this->userWithIdentities('b'))['roles']['mode']);

        $both = $this->forUser($this->userWithIdentities('a', 'b'));
        $this->assertSame(
            ['mode' => 'authoritative', 'editable' => false, 'sources' => ['a']],
            $both['roles']
        );
        $this->assertSame(['a', 'b'], $both['workgroups']['sources']);
    }

    public function test_identities_at_a_disabled_or_removed_provider_do_not_count(): void
    {
        $this->standaloneWithSso([
            'default' => $this->provider('Single Sign-On', rolesClaim: 'roles'),
            'retired' => $this->provider('Retired', rolesClaim: 'roles', overrides: ['enabled' => false]),
        ]);

        $sources = $this->forUser($this->userWithIdentities('retired', 'gone'));

        $this->assertSame('internal', $sources['roles']['mode']);
        $this->assertSame('internal', $sources['workgroups']['mode']);
    }

    public function test_nothing_is_managed_when_sso_is_off_even_for_linked_users(): void
    {
        config(['sso.enabled' => false]);

        $this->assertSame('internal', $this->forUser($this->userWithIdentities('default'))['roles']['mode']);
        $this->assertSame('internal', $this->deployment()['roles']['mode']);
        $this->assertEquals((object) [], $this->deployment()['providers']);
    }

    public function test_first_login_becomes_internal_once_the_user_has_synced(): void
    {
        $pending = $this->userWithIdentities('default');
        $this->assertSame(
            ['mode' => 'first_login', 'editable' => true, 'sources' => ['default']],
            $this->forUser($pending)['workgroups']
        );

        $synced = $this->userWithIdentities('default');
        $synced->forceFill(['claims_synced_at' => now()])->save();

        $this->assertSame(
            ['mode' => 'internal', 'editable' => true, 'sources' => []],
            $this->forUser($synced)['workgroups']
        );
    }

    public function test_integrated_users_are_managed_by_the_gateway_without_any_identity(): void
    {
        config(['system.operation_mode' => 'integrated', 'sso.enabled' => false]);

        $user = $this->userWithIdentities();
        $sources = $this->forUser($user);

        $this->assertSame(['gateway'], $sources['roles']['sources']);
        $this->assertSame('authoritative', $sources['roles']['mode']);
        $this->assertSame(['gateway'], $sources['workgroups']['sources']);
        $this->assertSame('internal', $sources['custodians']['mode']);

        $user->forceFill(['claims_synced_at' => now()])->save();
        $this->assertSame('internal', $this->forUser($user)['workgroups']['mode']);

        $this->assertEquals((object) ['gateway' => 'HDR UK Gateway'], $this->deployment()['providers']);
    }

    public function test_an_unknown_trust_value_is_internal(): void
    {
        $this->trust(ClaimSyncPolicy::SUBJECT_ROLES, 'sometimes', true);

        $this->assertSame('internal', $this->deployment()['roles']['mode']);
        $this->assertSame('internal', $this->forUser($this->userWithIdentities('default'))['roles']['mode']);
    }

    public function test_additive_stays_editable(): void
    {
        $this->trust(ClaimSyncPolicy::SUBJECT_ROLES, ClaimTrust::ALWAYS->value, false);

        $this->assertSame(
            ['mode' => 'additive', 'editable' => true, 'sources' => ['default']],
            $this->forUser($this->userWithIdentities('default'))['roles']
        );
    }

    public function test_an_enabled_provider_missing_its_secret_does_not_break_the_policy(): void
    {
        $this->standaloneWithSso([
            'default' => $this->provider('Single Sign-On', rolesClaim: 'roles', overrides: ['client_secret' => null]),
        ]);

        $this->assertSame('authoritative', $this->deployment()['roles']['mode']);
    }

    public function test_the_hybrid_deployment_reports_each_subject_and_its_providers(): void
    {
        $policy = $this->deployment();

        $this->assertSame('standalone', $policy['operation_mode']);
        $this->assertEquals((object) ['default' => 'Single Sign-On'], $policy['providers']);
        $this->assertSame(
            ['first_login', false, true],
            [$policy['workgroups']['mode'], $policy['workgroups']['authoritative'], $policy['workgroups']['editable']]
        );
        $this->assertSame(
            ['authoritative', true, false],
            [$policy['roles']['mode'], $policy['roles']['authoritative'], $policy['roles']['editable']]
        );
        $this->assertSame(
            ['internal', false, true],
            [$policy['custodians']['mode'], $policy['custodians']['authoritative'], $policy['custodians']['editable']]
        );
    }

    public function test_a_subject_nothing_syncs_is_never_reported_as_authoritative(): void
    {
        $this->trust(ClaimSyncPolicy::SUBJECT_CUSTODIANS, ClaimTrust::NEVER->value, true);

        $this->assertSame(
            ['internal', false],
            [$this->deployment()['custodians']['mode'], $this->deployment()['custodians']['authoritative']]
        );
    }

    public function test_restored_on_sync_lists_the_default_workgroup_and_role(): void
    {
        $restored = $this->deployment()['workgroups']['restored_on_sync'];

        $this->assertSame(
            [[
                'id' => Workgroup::where('name', 'DEFAULT')->value('id'),
                'name' => 'DEFAULT',
                'when' => 'every_sync',
            ]],
            $restored
        );
        $this->assertSame(['user'], $this->restoredNames('roles'));
        $this->assertSame([], $this->restoredNames('custodians'));
    }

    public function test_restored_on_sync_is_empty_when_defaults_are_not_ensured(): void
    {
        config([
            'claimsaccesscontrol.sync.workgroups.ensure_default' => false,
            'claimsaccesscontrol.sync.roles.ensure_default' => false,
        ]);

        $this->assertSame([], $this->restoredNames('workgroups'));
        $this->assertSame([], $this->restoredNames('roles'));
    }

    public function test_restored_on_sync_is_empty_for_a_subject_nothing_syncs(): void
    {
        $this->trust(ClaimSyncPolicy::SUBJECT_WORKGROUPS, ClaimTrust::NEVER->value, false);

        $this->assertSame([], $this->restoredNames('workgroups'));
    }

    public function test_sde_workgroups_are_restored_only_in_integrated_mode(): void
    {
        $this->assertSame(['DEFAULT'], $this->restoredNames('workgroups'));

        config(['system.operation_mode' => 'integrated']);
        $restored = $this->deployment()['workgroups']['restored_on_sync'];

        $this->assertSame(
            ['DEFAULT' => 'every_sync', 'NHS-SDE' => 'sde_approved', 'UK-INDUSTRY' => 'sde_approved', 'UK-RESEARCH' => 'sde_approved'],
            collect($restored)->pluck('when', 'name')->sortKeys()->all()
        );

        config(['claimsaccesscontrol.sync.workgroups.sde_from_claim' => false]);
        $this->assertSame(['DEFAULT'], $this->restoredNames('workgroups'));
    }

    public function test_removing_an_sde_workgroup_reports_restoration_as_unknown(): void
    {
        config(['system.operation_mode' => 'integrated']);
        $user = $this->userWithIdentities();
        $sources = app(MembershipSources::class);

        $sde = Workgroup::where('name', 'NHS-SDE')->firstOrFail();
        $other = Workgroup::updateOrCreate(['name' => 'OTHER'], ['active' => 1]);
        $default = Workgroup::where('name', 'DEFAULT')->firstOrFail();

        $this->assertNull($sources->workgroupEditWarning($user, $sde, 'detached')['restored_on_sync']);
        $this->assertFalse($sources->workgroupEditWarning($user, $other, 'detached')['restored_on_sync']);
        $this->assertTrue($sources->workgroupEditWarning($user, $default, 'detached')['restored_on_sync']);
        $this->assertFalse($sources->workgroupEditWarning($user, $sde, 'attached')['restored_on_sync']);
    }

    public function test_one_instance_serves_the_whole_request(): void
    {
        $this->assertSame(app(MembershipSources::class), app(MembershipSources::class));
    }

    public function test_malformed_sde_names_are_skipped_rather_than_breaking_the_policy(): void
    {
        config([
            'system.operation_mode' => 'integrated',
            'workgroups.sde_approved' => ['nhs-sde', ['uk-research'], null, ''],
        ]);

        $this->assertSame(['DEFAULT', 'NHS-SDE'], $this->restoredNames('workgroups'));
    }

    public function test_sde_workgroups_fall_back_to_the_shipped_list_when_config_leaves_them_out(): void
    {
        config(['system.operation_mode' => 'integrated', 'workgroups' => ['seed' => []]]);

        $this->assertEqualsCanonicalizing(['DEFAULT', 'NHS-SDE', 'UK-INDUSTRY', 'UK-RESEARCH'], $this->restoredNames('workgroups'));

        config(['workgroups.sde_approved' => null]);

        $this->assertEqualsCanonicalizing(['DEFAULT', 'NHS-SDE', 'UK-INDUSTRY', 'UK-RESEARCH'], $this->restoredNames('workgroups'));
    }

    public function test_a_default_workgroup_missing_from_the_database_is_left_out(): void
    {
        \DB::beginTransaction();

        try {
            \DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            Workgroup::where('name', 'DEFAULT')->delete();

            $this->assertSame([], $this->restoredNames('workgroups'));
        } finally {
            \DB::rollBack();
            \DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }
}
