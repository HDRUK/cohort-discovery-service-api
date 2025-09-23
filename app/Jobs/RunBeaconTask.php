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
        $submittedQuery = $task->submittedQuery;
        $rawQuery = $submittedQuery->definition;
        $task_type = $task->task_type;

        if ($task_type === TaskType::B) {
            //do a full raw query
            $rawQuery = [];
            //note - not fully implemented
            // - need to do a query for males and females too
            // - will return to this properly in the future
            /*
             'rules' => [
                    'rules' => [
                        'field' => 'sex',
                        'operator' => '==',
                        'value' => '8532',
                    ]
                ]
            */
        }

        $translatedQuery = $contextManager->handle($rawQuery, QueryContextType::Beacon);

        $url = $task->collection->url . '/api/individuals';

        //$host = $task->collection->host;
        //$user = $host->client_id;
        //$pass = $host->client_secret;

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->retry(3, 250)
                // ->withBasicAuth($user, $pass)        
                ->post($url, $translatedQuery);

            $response->throw();
            $data = $response->json();
            $count = $data['responseSummary']['numTotalResults'];

            Result::create([
                'task_id' => $task->id,
                'count' => (int) $count,
                'metadata' => $data,
                'status' => 'ok',
                'message' => 'success'
            ]);

            $task->update([
                'completed_at' => now(),
                'failed_at' => NULL
            ]);
            $task->save();

            if ($task_type === TaskType::B) {
                $now = now();
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
            }


            Log::info('Query posted successfully', ['url' => $url, 'status' => $response->status(), 'response' => $data]);
        } catch (Throwable $e) {
            Log::error('POST failed', ['url' => $url, 'error' => $e->getMessage()]);

            $task->update([
                'failed_at' => now()
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
    }
}
