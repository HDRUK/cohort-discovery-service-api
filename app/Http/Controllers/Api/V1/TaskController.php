<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDistributionFile;
use App\Models\Collection;
use App\Models\Result;
use App\Models\ResultFile;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\QueryContext\QueryContextManager;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Str;
use Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Tasks",
 *     description="Endpoints for managing and retrieving background processing tasks"
 * )
 */
class TaskController extends Controller
{
    use HelperFunctions;
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/tasks",
     *     summary="List tasks submitted by the authenticated user",
     *     tags={"Tasks"},
     *     @OA\Response(
     *         response=200,
     *         description="List of tasks",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Task"))
     *     )
     * )
     */
    public function getTasks(): JsonResponse
    {
        $tasks = Task::whereHas('submittedQuery', function ($query) {
            $query->where('user_id', Auth::id());
        });

        return $this->OKResponse($tasks);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/tasks/{taskPid}",
     *     summary="Get a single task by public pid",
     *     tags={"Tasks"},
     *     @OA\Parameter(
     *         name="taskPid",
     *         in="path",
     *         description="Public task pid (uuid)",
     *         required=true,
     *         @OA\Schema(type="string", example="tsk_abc123")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task object with related query, collection and result",
     *         @OA\JsonContent(ref="#/components/schemas/Task")
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function getTask($task_pid): JsonResponse
    {
        $task = Task::with(['submittedQuery', 'collection', 'result'])
            ->where('pid', $task_pid)
            ->first();

        if (! $task) {
            return $this->NotFoundResponse();
        }

        if (Gate::denies('view', $task)) {
            return $this->ForbiddenResponse();
        }

        return $this->OKResponse($task);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/tasks/next/{collectionPid}",
     *     summary="Retrieve the next job for a collection from the queue (Bunny worker)",
     *     tags={"Tasks"},
     *     @OA\Parameter(
     *         name="collectionPid",
     *         in="path",
     *         description="Collection pid, optionally suffixed with a dot and task type (e.g. col_abc123.a)",
     *         required=true,
     *         @OA\Schema(type="string", example="col_abc123.a")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Job payload required by the worker",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="task_id", type="integer", example=22),
     *             @OA\Property(property="uuid", type="string", example="tsk_abc123"),
     *             @OA\Property(property="cohort", type="object", nullable=true),
     *             @OA\Property(property="analysis", type="string", nullable=true),
     *             @OA\Property(property="collection", type="string", example="col_abc123"),
     *             @OA\Property(property="protocol_version", type="string", example="v2")
     *         )
     *     ),
     *     @OA\Response(response=204, description="No job available for this collection"),
     *     @OA\Response(response=400, description="Bad Request - invalid task type or other input"),
     *     @OA\Response(response=404, description="Collection not found")
     * )
     */
    public function nextJob(Request $request, string $collectionId, QueryContextManager $contextManager): JsonResponse|Response
    {
        // note - it'd be better if BUNNY could give us a worker ID in the headers
        // - also could give us some information like the BUNNY version it is using (git sha?)
        $workerId =  $request->ip();

        $parts = explode('.', $collectionId);
        $parsedId = $parts[0];
        $rawType = $parts[1] ?? 'a';

        try {
            $taskType = TaskType::from($rawType);
        } catch (\ValueError $e) {
            return $this->BadRequestResponseExtended("Invalid task type: '{$rawType}'. Allowed types are: 'a', 'b'.");
        }

        \Log::info($workerId. ' - Looking for new job for '.$collectionId);
        $collection = Collection::where('pid', $parsedId)->first();

        if (! $collection) {
            return $this->NotFoundResponse();
        }

        // Always log activity, regardless of if jobs exist
        Collection::logActivity($collection, $taskType);

        $nMaxAttempts = config('tasks.default_max_attempts', 3);
        $leaseSeconds =  config('tasks.default_lease_seconds', 10);
        /*
        //Note - temporary disable - this locking of tasks logic might be bugged


        $task = DB::transaction(function () use ($taskType, $collection, $nMaxAttempts, $leaseSeconds, $workerId) {
            $now = Carbon::now();

            $q = Task::where([
                    'task_type' => $taskType,
                    'completed_at' => null,
                    'collection_id' => $collection->id,
                ])
                ->where('attempts', '<', $nMaxAttempts)
                ->where(function ($q) use ($now) {
                    $q->whereNull('leased_until')
                      ->orWhere('leased_until', '<', $now);
                })
                ->orderBy('id')
                ->lockForUpdate();


            $task = $q->first();

            if (! $task) {
                return null;
            }

            $newAttempt = (int) $task->attempts + 1;

            $task->update([
                'leased_until' => $now->copy()->addSeconds($leaseSeconds),
                'leased_by' => $workerId,
            ]);


            TaskRun::create([
                'task_id' => $task->id,
                'attempt' => $newAttempt,
                'worker_id' => $workerId,
                'status' => 'claimed',
                'claimed_at' => $now,
                'started_at' => $now,
            ]);

            return $task->fresh();
        });
        */

        $now = Carbon::now();
        $q = Task::where([
                'task_type' => $taskType,
                'completed_at' => null,
                'collection_id' => $collection->id,
            ])
            ->where('attempts', '<', $nMaxAttempts)
            ->where(function ($q) use ($now) {
                $q->whereNull('leased_until')
                  ->orWhere('leased_until', '<', $now);
            })
            ->orderBy('id');
        // ->lockForUpdate(); // DISABLED

        $task = $q->first();

        if ($task) {
            $newAttempt = (int) $task->attempts + 1;

            $task->update([
                'leased_until' => $now->copy()->addSeconds($leaseSeconds),
                'leased_by' => $workerId,
            ]);

            TaskRun::create([
                'task_id' => $task->id,
                'attempt' => $newAttempt,
                'worker_id' => $workerId,
                'status' => 'claimed',
                'claimed_at' => $now,
                'started_at' => $now,
            ]);

            $task = $task->fresh();
        }


        if (! $task) {
            error_log('returning no content');

            return $this->NoContentResponse();
        }

        $nextAttempts = $task->attempts + 1;
        $task->attempts = $nextAttempts;
        $task->attempted_at = now();

        if ($nextAttempts === $nMaxAttempts) {
            $task->failed_at = now();
            $task->completed_at = now();
        }

        $task->save();
        $task->refresh()->load('submittedQuery');

        $submittedQuery = $task->submittedQuery;
        $rawQuery = $submittedQuery->definition;

        if ($taskType === TaskType::B) {
            $code = $rawQuery['code'] ?? 'DEMOGRAPHICS';
            $allowedCodes = ['DEMOGRAPHICS', 'GENERIC', 'ICD-MAIN'];

            if (! in_array($code, $allowedCodes)) {
                $task->failed_at = now();
                $task->completed_at = now();
                $task->save();
                return $this->BadRequestResponseExtended("Invalid distribution query code: {$code}");
            }

            return $this->OKResponseSimple([
                'task_id' => $task->id,
                'uuid' => $task->pid,
                'owner' => $rawQuery['owner'] ?? 'user1',
                'code' => $code,
                'analysis' => 'DISTRIBUTION',
                'collection' => $collection->pid,
                'protocol_version' => 'v2',
            ]);
        }

        $translatedQuery = null;
        try {
            $contextType = $collection->type;
            $translatedQuery = $contextManager->handle($rawQuery, $contextType);
        } catch (\ValueError $e) {
            $message = 'Unsupported collection type';
            TaskRun::where('task_id', $task->id)->where('attempt', $task->attempts)
            ->update([
                    'finished_at' => Carbon::now(),
                    'error_class' => get_class($e),
                    'error_message' => $message,
            ]);
            return $this->BadRequestResponseExtended($message);
        } catch (\Throwable $e) {
            TaskRun::where('task_id', $task->id)->where('attempt', $task->attempts)
            ->update([
                    'finished_at' => Carbon::now(),
                    'error_class' => get_class($e),
                    'error_message' => mb_strimwidth($e->getMessage(), 0, 2000, '…'),
                ]);
            return $this->ErrorResponse($e->getMessage());
        }

        if (! $translatedQuery) {
            return $this->BadRequestResponseExtended('Context manager failed to translate query');
        }

        // response needed by Bunny
        return $this->OKResponseSimple([
            'task_id' => $task->id,
            'uuid' => $task->pid,
            'cohort' => $translatedQuery,
            'project' => 'unknown_project', // ??
            'owner' => '1', // ??
            'collection' => $collection->pid,
            'protocol_version' => 'v2', // ??
            'char_salt' => bin2hex(random_bytes(4)), // ??
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/tasks/{taskPid}/collections/{collectionPid}/result",
     *     summary="Receive task result payload from worker",
     *     tags={"Tasks"},
     *     @OA\Parameter(
     *         name="taskPid",
     *         in="path",
     *         description="Task public pid (uuid)",
     *         required=true,
     *         @OA\Schema(type="string", example="tsk_abc123")
     *     ),
     *     @OA\Parameter(
     *         name="collectionPid",
     *         in="path",
     *         description="Collection public pid",
     *         required=true,
     *         @OA\Schema(type="string", example="col_abc123")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"status","queryResult"},
     *             @OA\Property(property="status", type="string", example="COMPLETED"),
     *             @OA\Property(property="message", type="string", nullable=true, example="Completed successfully"),
     *             @OA\Property(
     *                 property="queryResult",
     *                 type="object",
     *                 required={"count"},
     *                 @OA\Property(property="count", type="integer", example=123),
     *                 @OA\Property(
     *                     property="files",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="file_name", type="string", example="res_abc123.csv"),
     *                         @OA\Property(property="file_type", type="string", example="text/csv"),
     *                         @OA\Property(property="file_description", type="string", nullable=true),
     *                         @OA\Property(property="file_data", type="string", description="Base64-encoded file contents")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Result received and stored",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Result received successfully.")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Bad Request - invalid payload"),
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function receiveResult(Request $request, $task_pid, $collection_pid): JsonResponse
    {

        try {
            DB::transaction(function () use ($request, $task_pid) {

                $task = Task::where('pid', $task_pid)->lockForUpdate()->first();
                if (! $task) {
                    throw new \RuntimeException('Task not found');
                }

                if ($task->completed_at) {
                    return;
                }


                $status = $request->get('status');
                $message = $request->get('message');
                $queryResult = $request->get('queryResult');

                if (!is_array($queryResult) || !isset($queryResult['count']) || !is_numeric($queryResult['count'])) {
                    throw new \InvalidArgumentException('Invalid or missing count in queryResult.');
                }

                $count = (int) $queryResult['count'];

                // a header from BUNNY would be better if multiple runners on the same IP
                $workerId =  $request->ip();

                $run = TaskRun::firstOrCreate(
                    [
                        'task_id' => $task->id,
                        'attempt' => $task->attempts,
                    ],
                    [
                        'worker_id' => $workerId,
                        'status' => 'claimed',
                        'claimed_at' => $task->started_at ?? Carbon::now(),
                        'started_at' => $task->started_at ?? Carbon::now(),
                    ]
                );


                $metadata = collect($queryResult)->except('count')->toArray();
                $storedFiles = [];

                foreach ($metadata['files'] ?? [] as $file) {
                    if (!isset($file['file_data'])) {
                        continue;
                    }

                    $fileName = $file['file_name'] ?? 'unknown';
                    $fileType = $file['file_type'] ?? null;
                    $fileDescription = $file['file_description'] ?? null;

                    $decoded = base64_decode($file['file_data'], true);
                    if ($decoded === false) {
                        continue;
                    }

                    $pid  = (string) Str::uuid();
                    $path = "{$pid}-{$fileName}";

                    Storage::put($path, $decoded);

                    $resultFile = ResultFile::updateOrCreate(
                        ['pid' => $pid],
                        [
                            'task_id' => $task->id,
                            'collection_id' => $task->collection->id,
                            'path' => $path,
                            'file_name' => $fileName,
                            'file_type' => $fileType,
                            'file_description' => $fileDescription,
                            'status' => ResultFile::STATUS_QUEUED,
                        ]
                    );

                    ProcessDistributionFile::dispatch($resultFile->id)->afterCommit();

                    $storedFiles[] = [
                        'file_name' => $fileName,
                        'file_type' => $fileType,
                        'file_description' => $fileDescription,
                        'path' => $path,
                    ];
                }

                $resultMetadata = !empty($storedFiles) ? ['parsed_files' => $storedFiles] : $metadata;

                Result::updateOrCreate(
                    ['task_id' => $task->id],
                    [
                        'count' => $count,
                        'metadata' => $resultMetadata,
                        'status' => $status,
                        'message' => $message,
                    ]
                );

                $finishedAt = now();
                $durationMs = $run->started_at ? $run->started_at->diffInMilliseconds($finishedAt) : null;

                $run->update([
                    'finished_at' => $finishedAt,
                    'duration_ms' => $durationMs,
                    'result_status' => $status,
                    'error_class' => null,
                    'error_message' => null,
                ]);

                $task->update([
                    'completed_at' => $finishedAt,
                    'failed_at' => null,
                    'leased_until' => null,
                    'leased_by' => null,
                ]);
            });

            return $this->CreatedResponse([
                'message' => 'Result received successfully.',
            ]);

        } catch (\InvalidArgumentException $e) {
            $task = Task::where('pid', $task_pid)->first();
            if ($task) {
                TaskRun::where('task_id', $task->id)->where('attempt', $task->attempts)->update([
                    'finished_at' => Carbon::now(),
                    'error_class' => get_class($e),
                    'error_message' => mb_strimwidth($e->getMessage(), 0, 2000, '…'),
                ]);

                $task->update([
                    'completed_at' => Carbon::now(),
                    'failed_at' => Carbon::now(),
                    'leased_until' => null,
                    'leased_by' => null,
                ]);
            }

            return $this->BadRequestResponseExtended($e->getMessage());

        } catch (\Throwable $e) {
            $task = Task::where('pid', $task_pid)->first();
            if ($task) {
                TaskRun::where('task_id', $task->id)->where('attempt', $task->attempts)->update([
                    'finished_at' => Carbon::now(),
                    'error_class' => get_class($e),
                    'error_message' => mb_strimwidth($e->getMessage(), 0, 2000, '…'),
                ]);

                $task->update([
                    'failed_at' => Carbon::now(),
                ]);
            }

            Log::error($e->getMessage());
            return $this->ErrorResponse($e->getMessage());
        }
    }
    public function cloneTask(Request $request, string $pid): JsonResponse
    {
        try {
            $task = Task::where('pid', $pid)
                ->first();

            $query = $task->submittedQuery;
            $collection = $task->collection;

            $task = Task::create([
                'pid' => Str::uuid(),
                'query_id' => $query->id,
                'collection_id' => $collection->id,
                'created_at' => Carbon::now(),
                'task_type' => $task->task_type
            ]);

            return $this->OKResponse($task);
        } catch (\Throwable $e) {

            return $this->ErrorResponse($e->getMessage());
        }
    }

}
