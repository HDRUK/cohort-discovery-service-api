<?php

namespace App\Console\Commands;

use Log;
use Str;
use Carbon\Carbon;
use App\Contracts\ApiCommand;
use App\Enums\TaskType;
use App\Enums\QueryType;
use App\Models\Task;
use App\Models\Query;
use App\Models\CollectionConfig;
use App\Models\Collection;

class DistributionsCollector implements ApiCommand
{
    private string $tag = 'DistributionsCollector';
    private string $timezone = 'Europe/London';

    public function rules(): array
    {
        return [];
    }

    public function handle(array $validated): mixed
    {
        try {
            $counts = [
                'weekly' => 0,
                'monthly' => 0,
            ];

            $retVal = [
                'configs' => [],
            ];

            Log::info($this->tag . ' starting: ' . Carbon::now()->toDateTimeString());
            // Firstly gather all CollectionConfig meeting this current time
            // window. It should be noted, that while this collector runs on
            // a per minute interval, individual CollectionConfig's can be
            // configured to run at further specific times, such as weekly
            // or monthly. So, this initial pull only satifies Configs
            // that match the minute and hour of this collector's cycle.
            //
            $now = Carbon::now($this->timezone);

            $configs = CollectionConfig::where([
                'enabled' => 1,
                'run_time_hour' => $now->hour,
                'run_time_minute' => $now->minute,
            ])->get();

            Log::info($this->tag . ' found: ' . $configs->count() . ' to run: ' . $configs->toJson());

            $today = Carbon::now();

            foreach ($configs as $c) {
                switch ($c->frequency_mode) {
                    case 1:
                        // Weekly run mode
                        if ($today->day === $c->run_time_frequency) {
                            // This config is supposed to run now. It matches both time window
                            // of the collector and time frequency of the mode it is configured
                            // for.
                            Log::info($this->tag . ' config (' . $c->id . ') for collection (' .
                                $c->collection_id . ') running per weekly schedule');

                            $this->generateQueriesAndTasks($c);
                            $counts['weekly']++;
                        }
                        Log::info($this->tag . ' config (' . $c['id'] . ') for collection (' .
                            $c->collection_id . ') not scheduled to run - skipping.');
                        break;
                    case 2:
                        // Monthly run mode
                        if ($today->weekOfMonth === $c->run_time_frequency) {
                            // This config is supposed to run now. It matches both time window
                            // of the collector and time frequency of the mode it is configured
                            // for.
                            Log::info($this->tag . ' config (' . $c->id . ') for collection (' .
                                $c->collection_id . ') running per monthly schedule');

                            $this->generateQueriesAndTasks($c);
                            $counts['monthly']++;
                        }

                        Log::info($this->tag . ' config (' . $c['id'] . ') for collection (' .
                            $c->collection_id . ') not scheduled to run - skipping.');
                        break;
                    default:
                        Log::error($this->tag . ' CollectionConfig is set to unknown frequency_mode: ' .
                            $c->frequency_mode .
                            ' - frequency_mode should either be 1 (weekly) or 2 (monthly) - skipping.');
                        break;
                }

                $retVal['configs'][] = $c->toJson();
            }

            return [
                $counts,
                $retVal,
            ];
        } catch (\Throwable $e) {
            dd($e->getMessage());
        }
    }

    private function generateQueriesAndTasks(CollectionConfig $c): void
    {
        $collectionName = strtolower(str_replace(' ', '-', Collection::where('id', $c->collection_id)->value('name')));

        Log::info($this->tag . ' generating queries and tasks for ' . $collectionName . ' config (' . $c->id . ')');

        switch (strtolower($c->type)) {
            case TaskType::A->value: // Generic
                $query = Query::create([
                    'pid' => Str::uuid(),
                    'name' => 'omop-concept-job-' . $collectionName,
                    'definition' => [
                        'code' => QueryType::GENERIC->value,
                    ],
                ]);

                Log::info($this->tag . ' created Query: ' . $query->id . ' for config (' . $c->id . ')');

                $task = Task::create([
                    'pid' => Str::uuid(),
                    'query_id' => $query->id,
                    'collection_id' => $c->collection_id,
                    'created_at' => Carbon::now(),
                    'task_type' => TaskType::A->value,
                ]);

                Log::info($this->tag . ' created Task: ' . $task->id . ' for config (' . $c->id . ')');
                return;
            case TaskType::B->value: // Demographics
                $query = Query::create([
                    'pid' => Str::uuid(),
                    'name' => 'distribution-job-' . $collectionName,
                    'definition' => [
                        'code' => QueryType::DEMOGRAPHICS->value,
                    ],
                ]);

                Log::info($this->tag . ' created Query: ' . $query->id . ' for config (' . $c->id . ')');

                $task = Task::create([
                    'pid' => Str::uuid(),
                    'query_id' => $query->id,
                    'collection_id' => $c->collection_id,
                    'created_at' => Carbon::now(),
                    'task_type' => TaskType::B->value,
                ]);

                Log::info($this->tag . ' created Task: ' . $task->id . ' for config (' . $c->id . ')');
                return;
            default:
                Log::error($this->tag . ' attempting to createQueriesAndTasks with unknown TaskType: ' . $c->type .
                    'for collection_id (' . $c['collection_id'] . ') under config (' .
                    $c['id'] . ')');
                return;
        }
    }
}
