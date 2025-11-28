<?php

namespace App\Jobs;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TaskCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle()
    {
        $cutoff = now()->subHour();

        Task::where('created_at', '<', $cutoff)
            ->where('completed_at', null)
            ->update([
                'failed_at' => now(),
            ]);
    }
}
