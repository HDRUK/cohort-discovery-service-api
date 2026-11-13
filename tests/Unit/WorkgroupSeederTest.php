<?php

namespace Tests\Unit;

use App\Models\Workgroup;
use Database\Seeders\WorkgroupSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkgroupSeederTest extends TestCase
{
    private function countNamed(string $name): int
    {
        return Workgroup::where(DB::raw('LOWER(name)'), mb_strtolower($name))->count();
    }

    public function test_it_seeds_the_default_workgroups(): void
    {
        $this->seed(WorkgroupSeeder::class);

        foreach (config('workgroups.seed') as $name) {
            $this->assertSame(1, $this->countNamed($name), $name);
        }
    }

    public function test_it_seeds_exactly_the_configured_list_in_upper_case(): void
    {
        Workgroup::where(DB::raw('LOWER(name)'), 'breathe-consortium')->delete();
        config(['workgroups.seed' => ['breathe-consortium']]);

        $this->seed(WorkgroupSeeder::class);

        $this->assertTrue(Workgroup::where('name', 'BREATHE-CONSORTIUM')->exists());
    }

    public function test_a_second_run_creates_nothing(): void
    {
        config(['workgroups.seed' => ['default', 'breathe-consortium']]);

        $this->seed(WorkgroupSeeder::class);
        $before = Workgroup::count();

        $this->seed(WorkgroupSeeder::class);

        $this->assertSame($before, Workgroup::count());
    }

    public function test_an_existing_workgroup_in_another_case_is_not_duplicated(): void
    {
        Workgroup::where(DB::raw('LOWER(name)'), 'ncs-partners')->delete();
        Workgroup::create(['name' => 'Ncs-Partners', 'active' => 1]);
        config(['workgroups.seed' => ['ncs-partners']]);

        $this->seed(WorkgroupSeeder::class);

        $this->assertSame(1, $this->countNamed('ncs-partners'));
    }
}
