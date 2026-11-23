<?php

namespace Tests\Feature;

use App\Models\Custodian;
use DB;
use Tests\TestCase;

class ImportAdminUsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function importAdmin(): void
    {
        $this->artisan('admin-user:import', [
            '--name' => 'Jane Doe',
            '--email' => 'jane.doe@example.com',
            '--password' => 'example-password',
        ])->assertSuccessful();
    }

    public function test_it_does_not_store_the_local_id_as_the_external_custodian_id(): void
    {
        $this->importAdmin();

        $this->assertNull(Custodian::where('name', 'Health Data Research UK')->sole()->external_custodian_id);
    }

    public function test_it_keeps_the_gateway_team_id_of_an_existing_custodian(): void
    {
        Custodian::factory()->create(['name' => 'Health Data Research UK', 'external_custodian_id' => '21']);

        $this->importAdmin();

        $this->assertSame('21', Custodian::where('name', 'Health Data Research UK')->sole()->external_custodian_id);
    }
}
