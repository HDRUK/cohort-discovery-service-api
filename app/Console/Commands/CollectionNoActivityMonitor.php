<?php

namespace App\Console\Commands;

use Log;
use DB;
use Carbon\Carbon;
use App\Contracts\ApiCommand;
use App\Enums\CollectionStatus;
use App\Models\Collection;

class CollectionNoActivityMonitor implements ApiCommand
{
    private string $tag = 'CollectionNoActivityMonitor';
    private string $statusMessage = 'SUSPENDED_DUE_TO_INACTIVITY_24_HOURS';

    public function rules(): array
    {
        return [];
    }

    public function handle(array $validated): mixed
    {
        Log::info($this->tag . ' - Started');

        if (strtolower(config('system.collection_activity_log_type')) === 'log') {
            $colls = $this->getCollections();

            foreach ($colls as $c) {
                $lastRow = DB::select(
                    '
                        SELECT
                            MAX(created_at) AS created_at
                        FROM collection_activity_logs
                        WHERE collection_id = ?
                    ',
                    [ $c->id ]
                );

                if (!empty($lastRow)) {
                    $stamp = Carbon::parse($lastRow[0]->created_at);
                    if ($this->isNonActive($stamp)) {
                        $this->logNoActivity($c->id);
                        $this->setCollectionSuspended($c);
                    } else {
                        $this->logActivity($c->id);
                    }
                }
            }
        } elseif (strtolower(config('system.collection_activity_log_type')) === 'record') {
            $colls = $this->getCollections();

            foreach ($colls as $c) {
                if ($this->isNonActive($c->last_active)) {
                    $this->logNoActivity($c->id);
                    $this->setCollectionSuspended($c);
                } else {
                    $this->logActivity($c->id);
                }
            }
        }

        return 1;
    }

    private function setCollectionSuspended(\App\Models\Collection $c): void
    {
        $c->update([
            'status' => CollectionStatus::SUSPENDED->value,
            'status_msg' => $this->statusMessage,
        ]);
    }

    private function isNonActive(Carbon $stamp): bool
    {
        return $stamp->lt(Carbon::now()->subDay());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Collection>
     */
    private function getCollections(): \Illuminate\Database\Eloquent\Collection
    {
        return Collection::where([
                'status' => CollectionStatus::ACTIVE->value,
            ])->get();
    }

    private function logNoActivity(int $collectionId): void
    {
        // Flag as suspended, as the collection has seen no
        // activity for at least 24 hours.
        Log::info($this->tag . ' - found Collection (' . $collectionId . ') that has had ZERO ACTIVITY for 24 hours - flagging');
    }

    private function logActivity(int $collectionId): void
    {
        // Log, but ignore as this collection is actively being
        // polled for jobs - at least within the last 24 hours.
        Log::info($this->tag . ' - Collection (' . $collectionId . ') has had ACTIVITY within 24 hours - skipping');
    }
}
