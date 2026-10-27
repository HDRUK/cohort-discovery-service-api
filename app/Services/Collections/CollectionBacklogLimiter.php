<?php

namespace App\Services\Collections;

use App\Models\Collection;
use App\Models\Task;
use Laravel\Pennant\Feature;

class CollectionBacklogLimiter
{
    public const FEATURE = 'collection-task-backlog-limit';

    public const BLOCKED_REASON = 'Collection temporarily unavailable - too many pending tasks';

    public const ERROR_CLASS = 'CollectionBacklogExceeded';

    public function enabled(): bool
    {
        return Feature::active(self::FEATURE);
    }

    public function limit(): int
    {
        return (int) config('system.collection_pending_task_limit');
    }

    public function warningLimit(): int
    {
        return (int) config('system.collection_pending_task_warning_limit');
    }

    public function isSaturated(int $pendingCount): bool
    {
        return $this->enabled() && $pendingCount >= $this->limit();
    }

    public function isWarning(int $pendingCount): bool
    {
        return $this->enabled()
            && $pendingCount >= $this->warningLimit()
            && $pendingCount < $this->limit();
    }

    public function pendingCountFor(Collection $collection): int
    {
        return $this->pendingCountsFor([$collection->id])[$collection->id] ?? 0;
    }

    /**
     * @param  iterable<int>  $collectionIds
     * @return array<int, int>
     */
    public function pendingCountsFor(iterable $collectionIds): array
    {
        $ids = collect($collectionIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Task::query()
            ->whereNull('completed_at')
            ->whereIn('collection_id', $ids)
            ->groupBy('collection_id')
            ->selectRaw('collection_id, COUNT(*) as pending_count')
            ->pluck('pending_count', 'collection_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
