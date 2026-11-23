<?php

namespace Tests\Feature;

use App\Models\Custodian;
use DB;
use Tests\TestCase;

class ClearLocalExternalCustodianIdsMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_11_23_100000_clear_local_ids_from_custodians_external_custodian_id.php';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_it_clears_external_ids_that_are_the_custodians_own_local_id(): void
    {
        $imported = Custodian::factory()->create(['name' => 'Imported Trust']);
        $imported->update(['external_custodian_id' => (string) $imported->id, 'external_custodian_name' => 'Imported Trust']);
        $gateway = Custodian::factory()->create([
            'name' => 'Gateway Trust',
            'external_custodian_id' => '9999',
            'external_custodian_name' => 'Gateway Trust',
        ]);

        (require database_path(self::MIGRATION))->up();

        $imported->refresh();
        $this->assertNull($imported->external_custodian_id);
        $this->assertNull($imported->external_custodian_name);
        $this->assertSame('9999', $gateway->refresh()->external_custodian_id);
        $this->assertSame('Gateway Trust', $gateway->external_custodian_name);
    }
}
