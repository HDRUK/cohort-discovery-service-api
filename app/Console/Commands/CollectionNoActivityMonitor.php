<?php

namespace App\Console\Commands;

use App\Contracts\ApiCommand;
use App\Models\Collection;
use Hdruk\LaravelModelStates\Models\State;
use Carbon\Carbon;
use DB;
use Log;

class CollectionNoActivityMonitor implements ApiCommand
{
    private string $tag = 'CollectionNoActivityMonitor';

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
                    [$c->id]
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

    private function setCollectionSuspended(Collection $c): void
    {
        $c->modelState()->updateOrCreate(
            [],
            [
                'state_id' => State::query()
                    ->where('slug', Collection::STATUS_SUSPENDED)
                    ->valueOrFail('id'),
            ],
        );
    }

    private function isNonActive(?Carbon $stamp): bool
    {
        if ($stamp === null) {
            return true;
        }

        $minutes = (int) config('system.collection_inactivity_minutes', 30);

        return $stamp->lt(Carbon::now()->subMinutes($minutes));
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

    private function logNoActivity(int $collectionId): void
    {
        // Flag as suspended, as the collection has seen no
        // activity for at least X minutes.
        Log::info($this->tag . ' - found Collection (' . $collectionId . ') that has had NO ACTIVITY within threshold - flagging');
    }

    private function logActivity(int $collectionId): void
    {
        // Log, but ignore as this collection is actively being
        // polled for jobs - at least within the last X minutes.
        Log::info($this->tag . ' - Collection (' . $collectionId . ') has had RECENT ACTIVITY - skipping');
    }
}
