<?php

namespace Database\Seeders;

use App\Models\Custodian;
use App\Models\CustodianNetwork;
use App\Models\CustodianNetworkHasCustodian;
use Illuminate\Database\Seeder;

class CustodianNetworkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $network = CustodianNetwork::factory()->create();
        $custodians = Custodian::all();

        foreach ($custodians as $c) {
            // For now, add all custodians to this network
            CustodianNetworkHasCustodian::create([
                'network_id' => $network->id,
                'custodian_id' => $c->id,
            ]);
        }
    }
}
