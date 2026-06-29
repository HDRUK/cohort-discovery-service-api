<?php

namespace App\Console\Commands;

use App\DeploymentSteps\DeploymentStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeployRun extends Command
{
    protected $signature = 'deploy:run {--status : List all steps with their run status without executing anything}';

    protected $description = 'Run pending deployment steps';

    public function handle(): int
    {
        $files = $this->discoverSteps();

        if ($this->option('status')) {
            return $this->showStatus($files);
        }

        $this->output->writeln([
            '',
            '<comment>NOTE: Deployment steps are run-once and forward-only.</comment>',
            '<comment>      Steps already recorded in deployment_steps will be skipped.</comment>',
            '<comment>      There is no rollback — create a new step to undo any change.</comment>',
            '',
        ]);

        $ran = $this->getRanSteps();
        $pending = array_filter($files, fn ($key) => !isset($ran[$key]), ARRAY_FILTER_USE_KEY);

        if (empty($pending)) {
            $this->info('Nothing to run. All deployment steps are up to date.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Running %d pending step(s)...', count($pending)));

        foreach ($pending as $key => $path) {
            $this->output->writeln('');
            $this->output->write("  <fg=cyan>Running</> {$key} ... ");

            try {
                $step = require $path;

                if (!$step instanceof DeploymentStep) {
                    $this->output->writeln('<error>FAILED</error>');
                    $this->error("  Step file must return an instance of DeploymentStep.");
                    return self::FAILURE;
                }

                $step->setOutput($this->output);
                $step->handle();

                DB::table('deployment_steps')->insert([
                    'step'   => $key,
                    'ran_at' => now(),
                ]);

                $this->output->writeln('<info>DONE</info>');
            } catch (\Throwable $e) {
                $this->output->writeln('<error>FAILED</error>');
                $this->error("  {$e->getMessage()}");
                $this->error('  Halting. Fix the step and re-run deploy:run.');
                return self::FAILURE;
            }
        }

        $this->output->writeln('');
        $this->info('All pending deployment steps completed.');

        return self::SUCCESS;
    }

    private function showStatus(array $files): int
    {
        $ran = $this->getRanSteps();

        if (empty($files)) {
            $this->line('No deployment steps found.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($files as $key => $path) {
            $ranAt = $ran[$key] ?? null;
            $rows[] = [
                $key,
                $ranAt ? '<info>ran</info>' : '<comment>pending</comment>',
                $ranAt ?? '-',
            ];
        }

        $this->table(['Step', 'Status', 'Ran At'], $rows);

        $pendingCount = count(array_filter($rows, fn ($r) => str_contains($r[1], 'pending')));
        $this->line('');
        $this->line(sprintf('%d step(s) total, %d pending.', count($rows), $pendingCount));

        return self::SUCCESS;
    }

    /**
     * Returns an array keyed by step name (filename without .php), values are full paths.
     * Sorted alphabetically so date-prefixed names run in order.
     */
    private function discoverSteps(): array
    {
        $dir = config('deployment.steps_path', database_path('deployment-steps'));
        $files = glob("{$dir}/*.php") ?: [];
        sort($files);

        $steps = [];
        foreach ($files as $path) {
            $key = basename($path, '.php');
            $steps[$key] = $path;
        }

        return $steps;
    }

    /**
     * Returns step names already recorded, keyed by step name with ran_at as value.
     */
    private function getRanSteps(): array
    {
        return DB::table('deployment_steps')
            ->pluck('ran_at', 'step')
            ->all();
    }
}
