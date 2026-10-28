<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateServiceAccountCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ServiceAccount::truncate();
    }

    public function test_it_creates_a_service_account_with_a_hashed_secret(): void
    {
        $this->artisan('service-account:create', ['name' => 'scheduler'])
            ->expectsOutputToContain('Authorization: Basic ')
            ->assertSuccessful();

        $serviceAccount = ServiceAccount::where('name', 'scheduler')->firstOrFail();

        $this->assertNotEmpty($serviceAccount->client_id);
        $this->assertTrue(Hash::isHashed($serviceAccount->client_secret));
    }

    public function test_the_printed_credentials_match_the_stored_account(): void
    {
        Artisan::call('service-account:create', ['name' => 'scheduler']);

        $serviceAccount = ServiceAccount::where('name', 'scheduler')->firstOrFail();
        preg_match('/^'.preg_quote($serviceAccount->client_id, '/').':(\S+)$/m', Artisan::output(), $matches);

        $this->assertNotEmpty($matches);
        $this->assertTrue(Hash::check($matches[1], $serviceAccount->client_secret));
    }

    public function test_it_refuses_a_duplicate_name(): void
    {
        ServiceAccount::factory()->create(['name' => 'scheduler']);

        $this->artisan('service-account:create', ['name' => 'scheduler'])
            ->assertFailed();

        $this->assertSame(1, ServiceAccount::where('name', 'scheduler')->count());
    }

    public function test_it_regenerates_an_existing_service_account(): void
    {
        $original = ServiceAccount::factory()->create([
            'name' => 'scheduler',
            'client_secret' => 'old-secret',
        ]);

        Artisan::call('service-account:create', ['name' => 'scheduler', '--regenerate' => true]);

        $regenerated = ServiceAccount::where('name', 'scheduler')->firstOrFail();
        preg_match('/^'.preg_quote($regenerated->client_id, '/').':(\S+)$/m', Artisan::output(), $matches);

        $this->assertSame(1, ServiceAccount::where('name', 'scheduler')->count());
        $this->assertSame($original->id, $regenerated->id);
        $this->assertSame($original->client_id, $regenerated->client_id);
        $this->assertFalse(Hash::check('old-secret', $regenerated->client_secret));
        $this->assertTrue(Hash::check($matches[1], $regenerated->client_secret));
    }
}
