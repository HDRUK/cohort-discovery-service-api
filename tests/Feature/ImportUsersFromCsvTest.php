<?php

namespace Tests\Feature;

use App\Models\Custodian;
use DB;
use Tests\TestCase;

class ImportUsersFromCsvTest extends TestCase
{
    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->csv = tempnam(sys_get_temp_dir(), 'users');
        file_put_contents($this->csv, implode("\n", [
            'name,email,custodian,workgroup,role',
            'Jane Doe,jane.doe@example.com,Acme Trust,ADMIN,admin',
            'John Doe,john.doe@example.com,Beta Trust,ADMIN,admin',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);

        parent::tearDown();
    }

    public function test_it_neither_stores_local_ids_nor_overwrites_gateway_team_ids(): void
    {
        Custodian::factory()->create(['name' => 'Acme Trust', 'external_custodian_id' => '21']);

        $this->artisan('user:import-csv', ['--file' => $this->csv])->assertSuccessful();

        $this->assertSame('21', Custodian::where('name', 'Acme Trust')->sole()->external_custodian_id);
        $this->assertNull(Custodian::where('name', 'Beta Trust')->sole()->external_custodian_id);
    }
}
