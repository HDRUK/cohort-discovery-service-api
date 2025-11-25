<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Hdruk\LaravelModelStates\Models\State;

class StateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('model-states.states') as $slug) {
            State::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace('_', ' ', $slug))]
            );
        }
    }
}
