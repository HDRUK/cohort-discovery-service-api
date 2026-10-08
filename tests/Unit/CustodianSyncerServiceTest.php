<?php

namespace Tests\Unit;

use App\Models\Custodian;
use App\Models\User;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\TokenSync\CustodianSyncerService;
use DB;
use Tests\TestCase;

class CustodianSyncerServiceTest extends TestCase
{
    private CustodianSyncerService $syncer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
            'claimsaccesscontrol.sync.custodians.authoritative' => true,
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->syncer = app(CustodianSyncerService::class);
    }

    private function team(string $id, string $name): object
    {
        return (object) ['id' => $id, 'name' => $name];
    }

    private function custodianNamed(string $name): Custodian
    {
        return Custodian::where('name', $name)->firstOrFail();
    }

    public function test_it_creates_and_attaches_custodians_from_the_claim(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]));

        $this->assertTrue(
            $user->custodians()->where('custodians.id', $this->custodianNamed('Acme Trust')->id)->exists()
        );
    }

    public function test_it_upserts_on_the_external_id_rather_than_creating_duplicates(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);
        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust Renamed')]);

        $this->assertSame(1, Custodian::where('external_custodian_id', 'ext-1')->count());
        $this->assertSame('Acme Trust Renamed', $this->custodianNamed('Acme Trust Renamed')->name);
    }

    public function test_authoritative_sync_detaches_a_custodian_the_claim_omits(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust'), $this->team('ext-2', 'Beta Trust')]);
        $this->assertSame(2, $user->custodians()->count());

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertSame(['Acme Trust'], $user->custodians()->pluck('name')->all());
    }

    public function test_additive_sync_keeps_a_custodian_the_claim_omits(): void
    {
        config(['claimsaccesscontrol.sync.custodians.authoritative' => false]);
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust'), $this->team('ext-2', 'Beta Trust')]);
        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertSame(2, $user->custodians()->count());
    }

    public function test_an_absent_claim_leaves_custodians_untouched_under_authoritative_sync(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertFalse($this->syncer->sync($user, null));
        $this->assertSame(1, $user->custodians()->count());
    }

    public function test_a_present_but_empty_claim_clears_custodians_under_authoritative_sync(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertTrue($this->syncer->sync($user, []));
        $this->assertSame(0, $user->custodians()->count());
    }

    public function test_a_present_but_empty_claim_keeps_custodians_under_additive_sync(): void
    {
        config(['claimsaccesscontrol.sync.custodians.authoritative' => false]);
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertTrue($this->syncer->sync($user, []));
        $this->assertSame(1, $user->custodians()->count());
    }

    public function test_no_sync_happens_when_custodians_are_never_trusted(): void
    {
        config(['claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_NEVER]);
        $user = User::factory()->create();

        $this->assertFalse($this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]));
        $this->assertSame(0, $user->custodians()->count());
    }

    public function test_first_login_trust_only_syncs_for_an_unsynced_user(): void
    {
        config(['claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_FIRST_LOGIN]);
        $user = User::factory()->create();

        $this->assertTrue($this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')], null));
        $this->assertFalse($this->syncer->sync($user, [$this->team('ext-2', 'Beta Trust')], now()));

        $this->assertSame(['Acme Trust'], $user->custodians()->pluck('name')->all());
    }
}
