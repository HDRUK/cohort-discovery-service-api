<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeployRunTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = base_path('tests/Fixtures/deployment-steps');
    }

    private function useFixture(string $scenario): void
    {
        config(['deployment.steps_path' => "{$this->fixtures}/{$scenario}"]);
    }

    public function test_runs_pending_steps_and_records_them(): void
    {
        $this->useFixture('run-all');

        $this->artisan('deploy:run')->assertExitCode(0);

        $this->assertDatabaseHas('deployment_steps', ['step' => '2026_01_01_000001_no_op_a']);
        $this->assertDatabaseHas('deployment_steps', ['step' => '2026_01_01_000002_no_op_b']);
        $this->assertSame(2, DB::table('deployment_steps')->count());
    }

    public function test_skips_already_ran_steps(): void
    {
        $this->useFixture('skip-ran');

        DB::table('deployment_steps')->insert([
            'step'   => '2026_02_01_000001_no_op_c',
            'ran_at' => now(),
        ]);

        $this->artisan('deploy:run')->assertExitCode(0);

        // first step must remain a single row — not re-run or duplicated
        $this->assertSame(
            1,
            DB::table('deployment_steps')->where('step', '2026_02_01_000001_no_op_c')->count()
        );
        $this->assertDatabaseHas('deployment_steps', ['step' => '2026_02_01_000002_no_op_d']);
    }

    public function test_reports_nothing_to_run_when_all_steps_already_ran(): void
    {
        $this->useFixture('nothing-to-run');

        DB::table('deployment_steps')->insert([
            ['step' => '2026_03_01_000001_no_op_e', 'ran_at' => now()],
            ['step' => '2026_03_01_000002_no_op_f', 'ran_at' => now()],
        ]);

        $this->artisan('deploy:run')
            ->expectsOutputToContain('Nothing to run')
            ->assertExitCode(0);
    }

    public function test_halts_on_failing_step_and_does_not_record_it(): void
    {
        $this->useFixture('failure');

        $this->artisan('deploy:run')->assertExitCode(1);

        $this->assertDatabaseHas('deployment_steps', ['step' => '2026_04_01_000001_no_op_g']);
        $this->assertDatabaseMissing('deployment_steps', ['step' => '2026_04_01_000002_throws']);
    }

    public function test_status_shows_pending_and_ran_steps(): void
    {
        $this->useFixture('status');

        DB::table('deployment_steps')->insert([
            'step'   => '2026_05_01_000001_no_op_h',
            'ran_at' => now(),
        ]);

        $this->artisan('deploy:run --status')
            ->expectsOutputToContain('2026_05_01_000001_no_op_h')
            ->expectsOutputToContain('2026_05_01_000002_no_op_i')
            ->assertExitCode(0);
    }
}
