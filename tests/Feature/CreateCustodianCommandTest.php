<?php

namespace Tests\Feature;

use App\Models\Custodian;
use DB;
use Tests\TestCase;

class CreateCustodianCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_it_creates_a_custodian(): void
    {
        $this->artisan('custodian:create', ['name' => 'Acme Trust'])
            ->assertSuccessful();

        $this->assertDatabaseHas('custodians', ['name' => 'Acme Trust']);
    }

    public function test_it_is_idempotent(): void
    {
        $this->artisan('custodian:create', ['name' => 'Acme Trust'])->assertSuccessful();
        $this->artisan('custodian:create', ['name' => 'Acme Trust'])->assertSuccessful();

        $this->assertSame(1, Custodian::where('name', 'Acme Trust')->count());
    }

    public function test_it_matches_an_existing_custodian_case_insensitively(): void
    {
        Custodian::factory()->create(['name' => 'Acme Trust']);

        $this->artisan('custodian:create', ['name' => 'ACME TRUST'])->assertSuccessful();

        $this->assertSame(1, Custodian::whereRaw('LOWER(name) = ?', ['acme trust'])->count());
    }

    public function test_it_rejects_an_empty_name(): void
    {
        $this->artisan('custodian:create', ['name' => '   '])->assertFailed();

        $this->assertDatabaseMissing('custodians', ['name' => '']);
    }
}
