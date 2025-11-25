<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CustodianNetwork;
use App\Models\Custodian;
use App\Models\CustodianNetworkHasCustodian;

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
