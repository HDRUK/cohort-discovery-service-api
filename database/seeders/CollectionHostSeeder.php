<?php

namespace Database\Seeders;

use App\Models\CollectionHost;
use App\Models\Custodian;
use Illuminate\Database\Seeder;

class CollectionHostSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $custodians = Custodian::all();
        foreach ($custodians as $custodian) {
            CollectionHost::factory()->create([
                'name' => 'Test Collection Host for '.$custodian->name,
                'query_context_type' => fake()->randomElement(['bunny', 'beacon']),
                'client_id' => 'test-client-'.$custodian->id,
                'client_secret' => 'test-secret-'.$custodian->id,
                'custodian_id' => $custodian->id,
            ]);
        }
    }
}
