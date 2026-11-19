<?php

namespace Tests\Unit\Claims;

use App\Enums\ClaimTrust;
use App\Models\User;
use App\Providers\ClaimsAccessControlServiceProvider;
use App\Services\Claims\ClaimSyncPolicy;
use Carbon\CarbonInterface;
use Tests\TestCase;

class ClaimSyncPolicyTest extends TestCase
{
    private ClaimSyncPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = app(ClaimSyncPolicy::class);
    }

    private function withTrust(string $trust): void
    {
        config(['claimsaccesscontrol.sync.workgroups.trust' => $trust]);
    }

    public function test_never_refuses_a_sync_whether_or_not_the_user_has_synced_before(): void
    {
        $this->withTrust(ClaimTrust::NEVER->value);

        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, now()));
    }

    public function test_first_login_syncs_only_until_the_user_has_been_synced_once(): void
    {
        $this->withTrust(ClaimTrust::FIRST_LOGIN->value);

        $this->assertTrue($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, now()));
    }

    public function test_always_syncs_regardless_of_history(): void
    {
        $this->withTrust(ClaimTrust::ALWAYS->value);

        $this->assertTrue($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
        $this->assertTrue($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, now()));
    }

    public function test_an_unrecognised_trust_value_falls_back_to_never(): void
    {
        $this->withTrust('nonsense');

        $this->assertSame(
            ClaimTrust::NEVER,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_WORKGROUPS)
        );
        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
    }

    public function test_a_missing_trust_value_falls_back_to_never(): void
    {
        config(['claimsaccesscontrol.sync.workgroups' => []]);

        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
    }

    public function test_a_misspelt_trust_value_refuses_to_boot(): void
    {
        config(['claimsaccesscontrol.sync.roles.trust' => 'Always']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CLAIM_SYNC_ROLES is [Always]; expected one of: never, first_login, always');

        (new ClaimsAccessControlServiceProvider($this->app))->register();
    }

    public function test_a_missing_trust_value_refuses_to_boot(): void
    {
        config(['claimsaccesscontrol.sync.custodians' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CLAIM_SYNC_CUSTODIANS is [NULL]');

        (new ClaimsAccessControlServiceProvider($this->app))->register();
    }

    public function test_every_valid_trust_value_boots(): void
    {
        foreach (ClaimTrust::cases() as $trust) {
            foreach (ClaimSyncPolicy::SUBJECTS as $subject) {
                config(["claimsaccesscontrol.sync.{$subject}.trust" => $trust->value]);
            }

            (new ClaimsAccessControlServiceProvider($this->app))->register();
        }

        $this->assertTrue(true, 'every trust value registers without throwing');
    }

    public function test_a_misspelt_boolean_flag_refuses_to_boot(): void
    {
        config(['claimsaccesscontrol.sync.roles.authoritative' => 'flase']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CLAIM_SYNC_ROLES_AUTHORITATIVE is [flase]; expected one of: true, false, 1, 0');

        (new ClaimsAccessControlServiceProvider($this->app))->register();
    }

    public function test_a_missing_boolean_flag_refuses_to_boot(): void
    {
        config(['claimsaccesscontrol.sync.provision' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CLAIM_PROVISION_DEFAULTS_ON_CREATE is [NULL]');

        (new ClaimsAccessControlServiceProvider($this->app))->register();
    }

    public function test_every_accepted_boolean_spelling_boots(): void
    {
        $this->expectNotToPerformAssertions();

        foreach ([true, 'true', 1, '1', false, 'false', 0, '0'] as $value) {
            config([
                'claimsaccesscontrol.sync.workgroups.authoritative' => $value,
                'claimsaccesscontrol.sync.workgroups.ensure_default' => $value,
                'claimsaccesscontrol.sync.workgroups.sde_from_claim' => $value,
                'claimsaccesscontrol.sync.roles.authoritative' => $value,
                'claimsaccesscontrol.sync.roles.ensure_default' => $value,
                'claimsaccesscontrol.sync.custodians.authoritative' => $value,
                'claimsaccesscontrol.sync.provision.defaults_on_create' => $value,
            ]);

            (new ClaimsAccessControlServiceProvider($this->app))->register();
        }
    }

    public function test_a_blank_boolean_flag_boots_and_reads_as_false_as_it_did_before_validation(): void
    {
        config([
            'claimsaccesscontrol.sync.roles.authoritative' => '',
            'claimsaccesscontrol.sync.workgroups.ensure_default' => '',
        ]);

        (new ClaimsAccessControlServiceProvider($this->app))->register();

        $this->assertFalse($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_ROLES));
        $this->assertFalse($this->policy->shouldEnsureDefaultWorkgroup());
    }

    public function test_a_blank_trust_value_still_refuses_to_boot(): void
    {
        config(['claimsaccesscontrol.sync.roles.trust' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CLAIM_SYNC_ROLES is []');

        (new ClaimsAccessControlServiceProvider($this->app))->register();
    }

    public function test_string_and_numeric_flags_read_as_the_boolean_they_spell(): void
    {
        foreach (['false', '0', 0, false] as $falsy) {
            config(['claimsaccesscontrol.sync.workgroups.authoritative' => $falsy]);
            $this->assertFalse($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_WORKGROUPS), var_export($falsy, true));
        }

        foreach (['true', '1', 1, true] as $truthy) {
            config(['claimsaccesscontrol.sync.workgroups.authoritative' => $truthy]);
            $this->assertTrue($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_WORKGROUPS), var_export($truthy, true));
        }

        config([
            'claimsaccesscontrol.sync.workgroups.ensure_default' => 'false',
            'claimsaccesscontrol.sync.roles.ensure_default' => 'false',
            'claimsaccesscontrol.sync.workgroups.sde_from_claim' => 'false',
            'claimsaccesscontrol.sync.provision.defaults_on_create' => 'false',
        ]);

        $this->assertFalse($this->policy->shouldEnsureDefaultWorkgroup());
        $this->assertFalse($this->policy->shouldEnsureDefaultRole());
        $this->assertFalse($this->policy->shouldSyncSdeWorkgroupsFromClaim());
        $this->assertFalse($this->policy->shouldApplyDefaultsOnCreate());
    }

    public function test_claims_synced_at_reaches_the_policy_as_a_carbon_instance(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->claimsSyncedAt());

        $user->claims_synced_at = now();
        $user->save();

        $this->assertInstanceOf(CarbonInterface::class, $user->fresh()->claimsSyncedAt());
    }

    public function test_the_shipped_defaults_preserve_integrated_behaviour(): void
    {
        $this->assertSame(
            ClaimTrust::FIRST_LOGIN,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_WORKGROUPS)
        );
        $this->assertFalse($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_WORKGROUPS));

        $this->assertSame(
            ClaimTrust::ALWAYS,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_ROLES)
        );
        $this->assertTrue($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_ROLES));

        $this->assertSame(
            ClaimTrust::ALWAYS,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_CUSTODIANS)
        );
        $this->assertTrue($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_CUSTODIANS));

        $this->assertTrue($this->policy->shouldEnsureDefaultWorkgroup());
        $this->assertTrue($this->policy->shouldEnsureDefaultRole());
        $this->assertTrue($this->policy->shouldSyncSdeWorkgroupsFromClaim());
        $this->assertTrue($this->policy->shouldApplyDefaultsOnCreate());
    }
}
