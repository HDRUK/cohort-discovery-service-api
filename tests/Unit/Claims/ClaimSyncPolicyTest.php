<?php

namespace Tests\Unit\Claims;

use App\Models\User;
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
        $this->withTrust(ClaimSyncPolicy::TRUST_NEVER);

        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, now()));
    }

    public function test_first_login_syncs_only_until_the_user_has_been_synced_once(): void
    {
        $this->withTrust(ClaimSyncPolicy::TRUST_FIRST_LOGIN);

        $this->assertTrue($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, now()));
    }

    public function test_always_syncs_regardless_of_history(): void
    {
        $this->withTrust(ClaimSyncPolicy::TRUST_ALWAYS);

        $this->assertTrue($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
        $this->assertTrue($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, now()));
    }

    public function test_an_unrecognised_trust_value_falls_back_to_never(): void
    {
        $this->withTrust('nonsense');

        $this->assertSame(
            ClaimSyncPolicy::TRUST_NEVER,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_WORKGROUPS)
        );
        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
    }

    public function test_a_missing_trust_value_falls_back_to_never(): void
    {
        config(['claimsaccesscontrol.sync.workgroups' => []]);

        $this->assertFalse($this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, null));
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
            ClaimSyncPolicy::TRUST_FIRST_LOGIN,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_WORKGROUPS)
        );
        $this->assertFalse($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_WORKGROUPS));

        $this->assertSame(
            ClaimSyncPolicy::TRUST_ALWAYS,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_ROLES)
        );
        $this->assertTrue($this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_ROLES));

        $this->assertSame(
            ClaimSyncPolicy::TRUST_ALWAYS,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_CUSTODIANS)
        );

        $this->assertTrue($this->policy->shouldEnsureDefaultWorkgroup());
        $this->assertTrue($this->policy->shouldSyncSdeWorkgroupsFromClaim());
        $this->assertTrue($this->policy->shouldApplyDefaultsOnCreate());
    }
}
