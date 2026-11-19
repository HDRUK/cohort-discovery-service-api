<?php

namespace Database\Seeders;

use App\Models\Workgroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkgroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (config('workgroups.seed', []) as $name) {
            $exists = Workgroup::query()
                ->where(DB::raw('LOWER(name)'), mb_strtolower($name))
                ->exists();

            if (! $exists) {
                Workgroup::create([
                    'name' => mb_strtoupper($name),
                    'active' => 1,
                ]);
            }
        }
    }
}
