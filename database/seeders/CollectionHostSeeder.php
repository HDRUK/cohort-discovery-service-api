<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Custodian;
use App\Models\CollectionHost;

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
                'name' => 'Test Collection Host for ' . $custodian->name,
                'query_context_type' => fake()->randomElement(['bunny', 'beacon']),
                'client_id' => 'test-client-' . $custodian->id,
                'client_secret' => 'test-secret-' . $custodian->id,
                'custodian_id' => $custodian->id,
            ]);
        }
    }
}
