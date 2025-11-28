<?php

namespace Database\Seeders;

use Hdruk\LaravelModelStates\Models\State;
use Illuminate\Database\Seeder;

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
