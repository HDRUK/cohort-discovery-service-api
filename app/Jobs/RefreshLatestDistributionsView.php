<?php

namespace App\Jobs;

use App\Models\Collection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefreshLatestDistributionsView implements ShouldQueue
{
    use Queueable;

    private string $viewName = '';

    private string $distributionTable = '';

    public function __construct()
    {
        $mysqlDb = config('database.connections.mysql.database');

        $this->viewName          = "`{$mysqlDb}`.`latest_distributions`";
        $this->distributionTable = "`{$mysqlDb}`.`distributions`";
    }

    public function handle(): void
    {
        $beforeCount = null;
        try {
            $beforeCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->viewName}")->count ?? 0;
        } catch (\Throwable $e) {
            Log::warning('latest_distributions view count failed before refresh', [
                'view'  => $this->viewName,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('latest_distributions view count before refresh', [
            'view'  => $this->viewName,
            'count' => $beforeCount,
        ]);

        $resultFileIds = Collection::query()
            ->whereHas('latestSuccessfulConceptResultFile')
            ->withAggregate('latestSuccessfulConceptResultFile as latest_result_file_id', 'id')
            ->pluck('latest_result_file_id')
            ->filter()
            ->unique()
            ->values();

        $whereClause = '1 = 0';

        if ($resultFileIds->isNotEmpty()) {
            $idList = $resultFileIds
                ->map(fn ($id) => (int) $id)
                ->implode(',');

            $whereClause = "result_file_id IN ({$idList})";
        } else {
            Log::info('latest_distributions view refresh found no result files; creating empty view', [
                'view' => $this->viewName,
            ]);
        }

        DB::statement("
            CREATE OR REPLACE VIEW {$this->viewName} AS
            SELECT * FROM {$this->distributionTable}
            WHERE {$whereClause}
        ");

        $afterCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->viewName}")->count ?? 0;
        Log::info('latest_distributions view count after refresh', [
            'view'  => $this->viewName,
            'count' => $afterCount,
        ]);
    }
}
