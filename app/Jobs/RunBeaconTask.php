<?php

namespace App\Jobs;

use App\Enums\TaskType;
use App\Models\Distribution;
use App\Models\Result;
use App\Models\Task;
use App\Services\QueryContext\QueryContextManager;
use App\Services\QueryContext\QueryContextType;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunBeaconTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Task $task;

    /**
     * Create a new job instance.
     */
    public function __construct(Task $task)
    {
        $this->task = $task;
    }

    /**
     * Execute the job.
     */
    public function handle(QueryContextManager $contextManager): void
    {
        $task = $this->task;
        $url = $task->collection->url . '/api/individuals';
        $submittedQuery = $task->submittedQuery;
        $task_type = $task->task_type;

        $now = now();
        $nextAttempts = $task->attempts + 1;
        $task->attempts = $nextAttempts;
        $task->attempted_at = now();
        $task->save();

        if ($task_type === TaskType::A) {
            $rawQuery = $submittedQuery->definition;
            $translatedQuery = $contextManager->handle($rawQuery, QueryContextType::Beacon);

            try {
                $data = $this->postBeacon($url, $translatedQuery);
                $count = $data['responseSummary']['numTotalResults'];

                Result::create([
                    'task_id' => $task->id,
                    'count' => (int) $count,
                    'metadata' => $data,
                    'status' => 'ok',
                    'message' => 'success'
                ]);

                $task->update([
                    'completed_at' => $now,
                    'failed_at' => NULL
                ]);
                $task->save();
            } catch (Throwable $e) {
                $task->update([
                    'failed_at' => $now
                ]);
                $task->save();

                Result::create([
                    'task_id' => $task->id,
                    'count' => (int) -1,
                    'metadata' => [
                        'rawQuery' => $rawQuery,
                        'translatedQuery' => $translatedQuery
                    ],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);

                throw $e;
            }
        } elseif ($task_type === TaskType::B) {
            try {

                $beaconQuery = null;


                $data = $this->postBeacon($url, $beaconQuery);
                $count = $data['responseSummary']['numTotalResults'];
                Distribution::create(
                    [
                        'collection_id' => $task->collection->id,
                        'task_id'       => $task->id,
                        'category'      => 'DEMOGRAPHICS',
                        'name'          => (string) 'SEX',
                        'description'   => (string) 'Count from beacon',
                        'count'         => (int) $count,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]
                );

                $beaconQuery = [
                    'query' => [
                        'filters' => [
                            [
                                'id' => 'Gender:F'
                            ]
                        ]
                    ]
                ];

                $data = $this->postBeacon($url, $beaconQuery);
                $count = $data['responseSummary']['numTotalResults'];
                Distribution::create(
                    [
                        'collection_id' => $task->collection->id,
                        'task_id'       => $task->id,
                        'category'      => 'DEMOGRAPHICS',
                        'name'          => (string) 'Female',
                        'description'   => (string) 'Count from beacon',
                        'count'         => (int) $count,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]
                );

                $beaconQuery = [
                    'query' => [
                        'filters' => [
                            [
                                'id' => 'Gender:M'
                            ]
                        ]
                    ]
                ];

                $data = $this->postBeacon($url, $beaconQuery);
                $count = $data['responseSummary']['numTotalResults'];
                Distribution::create(
                    [
                        'collection_id' => $task->collection->id,
                        'task_id'       => $task->id,
                        'category'      => 'DEMOGRAPHICS',
                        'name'          => (string) 'Male',
                        'description'   => (string) 'Count from beacon',
                        'count'         => (int) $count,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]
                );
            } catch (Throwable $e) {
                $task->update([
                    'failed_at' => now()
                ]);
                $task->save();

                throw $e;
            }
        }
    }

    public function postBeacon(string $url, mixed $payload): array
    {
        $response = Http::acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(3, 250)
            // ->withBasicAuth($user, $pass)        
            ->post($url, $payload);

        $response->throw();
        $data = $response->json();
        return $data;
    }
}
