<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use Database\Seeders\HorizonServiceAccountSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HorizonServiceAccountSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ServiceAccount::truncate();
    }

    public function tearDown(): void
    {
        ServiceAccount::truncate();

        parent::tearDown();
    }

    public function test_it_creates_the_horizon_service_account_when_missing(): void
    {
        $this->seed(HorizonServiceAccountSeeder::class);

        $serviceAccount = ServiceAccount::where('name', ServiceAccount::HORIZON)->firstOrFail();

        $this->assertNotEmpty($serviceAccount->client_id);
        $this->assertTrue(Hash::isHashed($serviceAccount->client_secret));
    }

    public function test_running_it_again_keeps_the_existing_credentials(): void
    {
        $this->seed(HorizonServiceAccountSeeder::class);
        $original = ServiceAccount::where('name', ServiceAccount::HORIZON)->firstOrFail();

        $this->seed(HorizonServiceAccountSeeder::class);

        $this->assertSame(1, ServiceAccount::where('name', ServiceAccount::HORIZON)->count());
        $current = ServiceAccount::where('name', ServiceAccount::HORIZON)->firstOrFail();
        $this->assertSame($original->client_id, $current->client_id);
        $this->assertSame($original->client_secret, $current->client_secret);
    }

    public function test_it_leaves_other_service_accounts_alone(): void
    {
        $scheduler = ServiceAccount::factory()->create(['name' => 'scheduler']);

        $this->seed(HorizonServiceAccountSeeder::class);

        $this->assertSame($scheduler->client_secret, $scheduler->fresh()->client_secret);
        $this->assertSame(2, ServiceAccount::count());
    }
}
