<?php

namespace App\Console\Commands;

use App\Jobs\TaskCleanupJob;
use App\Contracts\ApiCommand;
use Carbon\Carbon;
use Log;

class TaskCleanup implements ApiCommand
{
    private string $tag = 'TaskCleanup';

    public function rules(): array
    {
        return [];
    }

    public function handle(array $validated): mixed
    {
        Log::info($this->tag . ' starting: ' . Carbon::now()->toDateTimeString());

        TaskCleanupJob::dispatch();

        Log::info($this->tag . ' spawned TaskCleanupJob: ' . Carbon::now()->toDateTimeString());
        Log::info($this->tag . ' finished: ' . Carbon::now()->toDateTimeString());

        return null;
    }
}
