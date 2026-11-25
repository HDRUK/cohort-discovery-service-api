<?php

namespace Database\Seeders;

use App\Models\ServiceAccount;
use Illuminate\Database\Seeder;

class HorizonServiceAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (ServiceAccount::where('name', ServiceAccount::HORIZON)->exists()) {
            return;
        }

        $this->command->call('service-account:create', ['name' => ServiceAccount::HORIZON]);
    }
}
