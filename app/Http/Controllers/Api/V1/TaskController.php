<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDistributionFile;
use App\Models\Collection;
use App\Models\Result;
use App\Models\ResultFile;
use App\Models\Task;
use App\Services\QueryContext\QueryContextManager;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Log;
use Carbon\Carbon;

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
    public function nextJob($collectionId, QueryContextManager $contextManager): JsonResponse|Response
    {
        $parts = explode('.', $collectionId);
        $parsedId = $parts[0];
        $rawType = $parts[1] ?? 'a';

        try {
            $taskType = TaskType::from($rawType);
        } catch (\ValueError $e) {
            return $this->BadRequestResponseExtended("Invalid task type: '{$rawType}'. Allowed types are: 'a', 'b'.");
        }

        \Log::info('Looking for new job for '.$collectionId);
        $collection = Collection::where('pid', $parsedId)->first();

        if (! $collection) {
            return $this->NotFoundResponse();
        }

        // Always log activity, regardless of if jobs exist
        Collection::logActivity($collection, $taskType);

        $nAttempts = config('api.default_max_attempts', 3);
        $task = Task::where([
            'task_type' => $taskType,
            'completed_at' => null,
            'collection_id' => $collection->id,
        ])
            ->where('attempts', '<', $nAttempts)
            ->first();

        if (! $task) {
            error_log('returning no content');

            return $this->NoContentResponse();
        }

        $nextAttempts = $task->attempts + 1;
        $task->attempts = $nextAttempts;
        $task->attempted_at = now();

        if ($nextAttempts === $nAttempts) {
            $task->failed_at = now();
        }

        $task->save();
        $task->refresh()->load('submittedQuery');

        $submittedQuery = $task->submittedQuery;
        $rawQuery = $submittedQuery->definition;

        if ($taskType === TaskType::B) {
            $code = $rawQuery['code'] ?? 'DEMOGRAPHICS';
            $allowedCodes = ['DEMOGRAPHICS', 'GENERIC', 'ICD-MAIN'];

            if (! in_array($code, $allowedCodes)) {
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
            return $this->BadRequestResponseExtended('Unsupported collection type');
        } catch (\Throwable $e) {
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
            $status = $request->get('status');
            $message = $request->get('message');
            $queryResult = $request->get('queryResult');

            if (! is_array($queryResult) || ! isset($queryResult['count']) || ! is_numeric($queryResult['count'])) {
                return $this->BadRequestResponseExtended('Invalid or missing count in queryResult.');
            }

            $count = $queryResult['count'];

            error_log("\033[32m[RESULT RECEIVED]\033[0m Status: {$status}, Count: {$count}, Task PID: {$task_pid}");

            $task = Task::where(['pid' => $task_pid])->first();

            if (! $task) {
                return $this->NotFoundResponse();
            }

            $metadata = collect($queryResult)->except('count')->toArray();
            $storedFiles = [];

            foreach ($metadata['files'] ?? [] as $file) {

                if (! isset($file['file_data'])) {
                    continue;
                }

                $fileName = $file['file_name'] ?? 'unknown';
                $fileType = $file['file_type'] ?? null;
                $fileDescription = $file['file_description'] ?? null;
                $fileDataBase64 = $file['file_data'];

                $decodedContent = base64_decode($fileDataBase64, true);

                if (! $decodedContent) {
                    continue;
                }

                $identifier = sprintf('%s-%s', $task->id, Carbon::now()->format('Ymd_His'));

                $hash = hash('sha256', $identifier);
                $path = sprintf('%s-%s', $hash, $fileName);


                try {
                    Log::debug('About to write file to storage', [
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                        'task_id' => $task->id ?? null,
                    ]);

                    $ok = Storage::put($path, $decodedContent);

                    if (! $ok) {
                        Log::error('Storage::put returned false', [
                            'disk' => config('filesystems.default'),
                            'path' => $path,
                            'size' => strlen($decodedContent),
                        ]);
                    } else {
                        Log::info('File written to storage successfully', [
                            'disk' => config('filesystems.default'),
                            'path' => $path,
                            'size' => strlen($decodedContent),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('Exception while writing to storage', [
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    throw $e;
                }

                Log::info('Creating file', [
                    'id' => $identifier,
                    'pid' => $hash,
                    'task_id' => $task->id,
                    'task_pid' => $task->pid,
                ]);

                $resultFile = ResultFile::create([
                    'pid' => $hash,
                    'task_id' => $task->id,
                    'collection_id' => $task->collection->id,
                    'path' => $path,
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'file_description' => $fileDescription,
                    'status' => ResultFile::STATUS_QUEUED,
                ]);

                ProcessDistributionFile::dispatch($resultFile->id);

                $storedFiles[] = [
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'file_description' => $fileDescription,
                    'path' => $path,
                ];
            }

            $resultMetadata = ! empty($storedFiles) ? ['parsed_files' => $storedFiles] : $metadata;

            Result::create([
                'task_id' => $task->id,
                'count' => (int) $count,
                'metadata' => $resultMetadata,
                'status' => $status,
                'message' => $message,
            ]);

            $task->update([
                'completed_at' => now(),
                'failed_at' => null,
            ]);
            $task->save();

            return $this->CreatedResponse([
                'message' => 'Result received successfully.',
            ]);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());
            return $this->ErrorResponse($e->getMessage());
        }
    }

<<<<<<< HEAD

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

=======
>>>>>>> origin/dev
}
