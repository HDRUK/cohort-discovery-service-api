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

            if ($this->backlogLimiter->isSaturated($pending)) {
                $this->suspend($c, $pending);
            } elseif ($this->backlogLimiter->isWarning($pending)) {
                $this->warn($c, $pending);
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
        $this->slackNotifier->collectionBacklogSuspended($c, $pending, $limit);
    }

    private function warn(Collection $c, int $pending): void
    {
        $limit = $this->backlogLimiter->limit();

        Log::warning($this->tag . ' - Collection (' . $c->id . ') has ' . $pending . ' pending tasks, approaching limit ' . $limit);

        if (! Cache::add($this->warningCacheKey($c), true, now()->addHour())) {
            return;
        }

        $this->slackNotifier->collectionBacklogWarning($c, $pending, $limit);
    }

    private function warningCacheKey(Collection $c): string
    {
        return 'collection-backlog-warned:' . $c->id;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Collection>
     */
    private function getCollections(): \Illuminate\Database\Eloquent\Collection
    {
        return Collection::whereRelation(
            'modelState.state',
            'slug',
            Collection::STATUS_ACTIVE
        )->get();
    }
}
