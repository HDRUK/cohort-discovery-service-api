<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workgroup;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\TokenSync\WorkgroupSyncerService;
use Tests\TestCase;

class WorkgroupSyncerServiceTest extends TestCase
{
    private WorkgroupSyncerService $syncer;

    private array $workgroups = [
        'ADMIN',
        'DEFAULT',
        'CUSTODIAN',
        'NON-UK-INDUSTRY',
        'NON-UK-RESEARCH',
        'OTHER',
        'UK-INDUSTRY',
        'UK-RESEARCH',
        'NHS-SDE',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'claimsaccesscontrol.sync.workgroups.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
            'claimsaccesscontrol.sync.workgroups.authoritative' => false,
            'claimsaccesscontrol.sync.workgroups.ensure_default' => false,
            'claimsaccesscontrol.sync.workgroups.sde_from_claim' => false,
        ]);

        // Other suites truncate and rebuild this table, and RefreshDatabaseLite
        // does not roll back between tests, so assert against a known shape
        // rather than whatever the last test happened to leave behind
        foreach ($this->workgroups as $name) {
            Workgroup::updateOrCreate(['name' => $name], ['active' => 1]);
        }

        $this->syncer = app(WorkgroupSyncerService::class);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function workgroup(string $name): Workgroup
    {
        return Workgroup::where('name', $name)->firstOrFail();
    }

    public function test_token_workgroups_resolve_via_the_workgroup_mappings_config(): void
    {
        $user = $this->user();

        $this->syncer->sync($user, ['uk-research'], false);

        $this->assertTrue(
            $user->workgroups()->where('workgroups.id', $this->workgroup('UK-RESEARCH')->id)->exists()
        );
    }

    public function test_claim_matching_is_case_insensitive(): void
    {
        $user = $this->user();

        $this->syncer->sync($user, ['UK-Research'], false);

        $this->assertTrue(
            $user->workgroups()->where('workgroups.id', $this->workgroup('UK-RESEARCH')->id)->exists()
        );
    }

    public function test_a_claim_value_that_differs_from_the_workgroup_name_still_resolves(): void
    {
        config(['claimsaccesscontrol.workgroup_mappings' => [
            'uk-research' => 'urn:example:group:researchers',
        ]]);

        $user = $this->user();

        $this->syncer->sync($user, ['urn:example:group:researchers'], false);

        $this->assertTrue(
            $user->workgroups()->where('workgroups.id', $this->workgroup('UK-RESEARCH')->id)->exists()
        );
    }

    public function test_privileged_workgroups_are_matchable(): void
    {
        $user = $this->user();

        $this->syncer->sync($user, ['admin', 'custodian'], false);

        $this->assertTrue(
            $user->workgroups()->where('workgroups.id', $this->workgroup('ADMIN')->id)->exists()
        );
        $this->assertTrue(
            $user->workgroups()->where('workgroups.id', $this->workgroup('CUSTODIAN')->id)->exists()
        );
    }

    public function test_unknown_claim_values_match_nothing(): void
    {
        $user = $this->user();

        $this->syncer->sync($user, ['not-a-workgroup'], false);

        $this->assertSame(0, $user->workgroups()->count());
    }

    public function test_an_empty_claim_attaches_nothing(): void
    {
        $user = $this->user();

        $this->syncer->sync($user, [], false);

        $this->assertSame(0, $user->workgroups()->count());
    }

    public function test_additive_sync_keeps_a_manually_assigned_workgroup(): void
    {
        $user = $this->user();
        $claimed = $this->workgroup('UK-RESEARCH');
        $manual = $this->workgroup('OTHER');

        $this->syncer->sync($user, ['uk-research'], false);

        // an admin adds a workgroup by hand after the first login
        $user->workgroups()->syncWithoutDetaching([$manual->id]);

        // ...and the user returns with the Gateway still silent about it
        $this->syncer->sync($user, ['uk-research'], false);

        $this->assertTrue($user->workgroups()->where('workgroups.id', $manual->id)->exists());
        $this->assertTrue($user->workgroups()->where('workgroups.id', $claimed->id)->exists());
    }

    public function test_authoritative_sync_removes_a_workgroup_the_claim_omits(): void
    {
        config(['claimsaccesscontrol.sync.workgroups.authoritative' => true]);

        $user = $this->user();
        $claimed = $this->workgroup('UK-RESEARCH');
        $manual = $this->workgroup('OTHER');

        $this->syncer->sync($user, ['uk-research'], false);
        $user->workgroups()->syncWithoutDetaching([$manual->id]);

        $this->syncer->sync($user, ['uk-research'], false);

        $this->assertFalse($user->workgroups()->where('workgroups.id', $manual->id)->exists());
        $this->assertTrue($user->workgroups()->where('workgroups.id', $claimed->id)->exists());
    }

    public function test_default_workgroup_is_added_when_configured(): void
    {
        config(['claimsaccesscontrol.sync.workgroups.ensure_default' => true]);

        $user = $this->user();

        $this->syncer->sync($user, [], false);

        $this->assertTrue(
            $user->workgroups()->where('workgroups.id', $this->workgroup('DEFAULT')->id)->exists()
        );
    }

    public function test_sde_approval_adds_the_nhs_workgroups(): void
    {
        config([
            'claimsaccesscontrol.sync.workgroups.ensure_default' => true,
            'claimsaccesscontrol.sync.workgroups.sde_from_claim' => true,
        ]);

        $user = $this->user();

        $this->syncer->sync($user, [], true);

        foreach (['NHS-SDE', 'UK-INDUSTRY', 'UK-RESEARCH'] as $name) {
            $this->assertTrue(
                $user->workgroups()->where('workgroups.id', $this->workgroup($name)->id)->exists(),
                "expected {$name} to be attached"
            );
        }
    }

    public function test_no_sync_happens_when_claims_are_never_trusted(): void
    {
        config(['claimsaccesscontrol.sync.workgroups.trust' => ClaimSyncPolicy::TRUST_NEVER]);

        $user = $this->user();

        $this->syncer->sync($user, ['uk-research'], false);

        $this->assertSame(0, $user->workgroups()->count());
    }

    public function test_sync_reports_whether_it_ran_so_callers_only_stamp_a_real_sync(): void
    {
        $user = $this->user();

        config(['claimsaccesscontrol.sync.workgroups.trust' => ClaimSyncPolicy::TRUST_NEVER]);
        $this->assertFalse($this->syncer->sync($user, ['uk-research'], false));

        config(['claimsaccesscontrol.sync.workgroups.trust' => ClaimSyncPolicy::TRUST_FIRST_LOGIN]);
        $this->assertTrue($this->syncer->sync($user, ['uk-research'], false, null));
        $this->assertFalse($this->syncer->sync($user, ['uk-research'], false, now()));
    }

    public function test_first_login_trust_only_syncs_for_an_unsynced_user(): void
    {
        config(['claimsaccesscontrol.sync.workgroups.trust' => ClaimSyncPolicy::TRUST_FIRST_LOGIN]);

        $user = $this->user();

        $this->syncer->sync($user, ['uk-research'], false, null);

        $this->assertTrue(
            $user->workgroups()->where('workgroups.id', $this->workgroup('UK-RESEARCH')->id)->exists()
        );

        $this->syncer->sync($user, ['admin'], false, now());

        $this->assertFalse(
            $user->workgroups()->where('workgroups.id', $this->workgroup('ADMIN')->id)->exists()
        );
    }
}
