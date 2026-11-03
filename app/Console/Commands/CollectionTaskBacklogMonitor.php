<?php

namespace App\Console\Commands;

use App\Contracts\ApiCommand;
use App\Models\Collection;
use App\Services\Activity\ActivityLogger;
use App\Services\Collections\CollectionBacklogLimiter;
use App\Services\Notifications\SlackNotifier;
use Illuminate\Support\Facades\Cache;
use Log;

class CollectionTaskBacklogMonitor implements ApiCommand
{
    private string $tag = 'CollectionTaskBacklogMonitor';

    public function __construct(
        private SlackNotifier $slackNotifier,
        private CollectionBacklogLimiter $backlogLimiter,
        private ActivityLogger $activityLogger
    ) {
    }

    public function rules(): array
    {
        return [];
    }

    public function handle(array $validated): mixed
    {
        Log::info($this->tag . ' - Started');

        if (! $this->backlogLimiter->enabled()) {
            Log::info($this->tag . ' - feature flag off, skipping');

            return 1;
        }

        $collections = $this->getCollections();
        $pendingCounts = $this->backlogLimiter->pendingCountsFor($collections->pluck('id'));

        foreach ($collections as $c) {
            $pending = $pendingCounts[$c->id] ?? 0;

            try {
                if ($this->backlogLimiter->isSaturated($pending)) {
                    $this->suspend($c, $pending);
                } elseif ($this->backlogLimiter->isWarning($pending)) {
                    $this->sendBacklogWarning($c, $pending);
                } else {
                    Cache::forget($this->notifiedCacheKey($c));
                }
            } catch (\Throwable $e) {
                Log::error($this->tag . ' - Collection (' . $c->id . ') failed: ' . $e->getMessage());
            }
        }

        return 1;
    }

    private function suspend(Collection $c, int $pending): void
    {
        $limit = $this->backlogLimiter->limit();

        Log::warning($this->tag . ' - Collection (' . $c->id . ') has ' . $pending . ' pending tasks (limit ' . $limit . ') - suspending');

        $this->activityLogger->custom('collections', 'task_backlog_suspended', $c, [
            'pending_tasks' => $pending,
            'limit' => $limit,
        ], null, true);

        $c->setState(Collection::STATUS_SUSPENDED);

        if ($this->shouldNotify($c)) {
            $this->slackNotifier->collectionBacklogSuspended($c, $pending, $limit);
        }
    }

    private function sendBacklogWarning(Collection $c, int $pending): void
    {
        $limit = $this->backlogLimiter->limit();

        Log::warning($this->tag . ' - Collection (' . $c->id . ') has ' . $pending . ' pending tasks, approaching limit ' . $limit);

        if ($this->shouldNotify($c)) {
            $this->slackNotifier->collectionBacklogWarning($c, $pending, $limit);
        }
    }

    private function shouldNotify(Collection $c): bool
    {
        return Cache::add($this->notifiedCacheKey($c), true, now()->addHour());
    }

    private function notifiedCacheKey(Collection $c): string
    {
        return 'collection-backlog-warned:' . $c->id;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Collection>
     */
    private function getCollections(): \Illuminate\Database\Eloquent\Collection
    {
        return Collection::with('custodian')->whereRelation(
            'modelState.state',
            'slug',
            Collection::STATUS_ACTIVE
        )->get();
    }
}
