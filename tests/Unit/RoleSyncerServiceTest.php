<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\TokenSync\RoleSyncerService;
use Tests\TestCase;

class RoleSyncerServiceTest extends TestCase
{
    private RoleSyncerService $syncer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'claimsaccesscontrol.sync.roles.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
            'claimsaccesscontrol.sync.roles.authoritative' => true,
            'claimsaccesscontrol.sync.roles.ensure_default' => false,
        ]);

        $this->syncer = app(RoleSyncerService::class);
    }

    private function roleNames(User $user): array
    {
        return $user->roles()->pluck('name')->sort()->values()->all();
    }

    public function test_claim_values_map_to_local_roles(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->syncer->sync($user, ['SYSTEM_ADMIN']));

        $this->assertSame(['admin'], $this->roleNames($user));
    }

    public function test_an_absent_claim_leaves_roles_untouched_under_authoritative_sync(): void
    {
        $user = User::factory()->create();
        $this->syncer->sync($user, ['SYSTEM_ADMIN']);

        $this->assertFalse($this->syncer->sync($user, null));

        $this->assertSame(['admin'], $this->roleNames($user));
    }

    public function test_a_present_but_empty_claim_clears_roles_when_no_floor_is_configured(): void
    {
        $user = User::factory()->create();
        $this->syncer->sync($user, ['GENERAL_ACCESS']);

        $this->assertTrue($this->syncer->sync($user, []));

        $this->assertSame([], $this->roleNames($user));
    }

    public function test_the_default_role_floor_survives_an_empty_claim(): void
    {
        config(['claimsaccesscontrol.sync.roles.ensure_default' => true]);
        $user = User::factory()->create();

        $this->syncer->sync($user, []);

        $this->assertSame(['user'], $this->roleNames($user));
    }

    public function test_the_default_role_floor_is_merged_alongside_claimed_roles(): void
    {
        config(['claimsaccesscontrol.sync.roles.ensure_default' => true]);
        $user = User::factory()->create();

        $this->syncer->sync($user, ['SYSTEM_ADMIN']);

        $this->assertSame(['admin', 'user'], $this->roleNames($user));
    }

    public function test_the_last_admin_keeps_the_admin_role(): void
    {
        $user = User::factory()->create();
        $this->syncer->sync($user, ['SYSTEM_ADMIN']);

        $this->syncer->sync($user, ['GENERAL_ACCESS']);

        $this->assertSame(['admin', 'user'], $this->roleNames($user));
    }

    public function test_an_admin_loses_the_role_while_another_admin_remains(): void
    {
        $other = User::factory()->create();
        $this->syncer->sync($other, ['SYSTEM_ADMIN']);

        $user = User::factory()->create();
        $this->syncer->sync($user, ['SYSTEM_ADMIN']);

        $this->syncer->sync($user, ['GENERAL_ACCESS']);

        $this->assertSame(['user'], $this->roleNames($user));
        $this->assertSame(['admin'], $this->roleNames($other));
    }

    public function test_a_non_admin_is_unaffected_by_the_last_admin_guard(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, ['GENERAL_ACCESS']);

        $this->assertSame(['user'], $this->roleNames($user));
    }

    public function test_no_sync_happens_when_roles_are_never_trusted(): void
    {
        config(['claimsaccesscontrol.sync.roles.trust' => ClaimSyncPolicy::TRUST_NEVER]);
        $user = User::factory()->create();

        $this->assertFalse($this->syncer->sync($user, ['SYSTEM_ADMIN']));

        $this->assertSame([], $this->roleNames($user));
    }
}
