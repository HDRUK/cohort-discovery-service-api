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
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class TaskController extends Controller
{
    use Responses;
    use HelperFunctions;


    public function getTasks(): JsonResponse
    {
        $tasks = Task::whereHas('submittedQuery', function ($query) {
            $query->where('user_id', Auth::id());
        });
        return $this->OKResponse($tasks);
    }


    public function getTask($task_pid): JsonResponse
    {
        $task = Task::with(['submittedQuery', 'collection', 'result'])
            ->where('pid', $task_pid)
            ->first();

        if (!$task) {
            return $this->NotFoundResponse();
        }

        if (Gate::denies('view', $task)) {
            return  $this->ForbiddenResponse();
        }

        return $this->OKResponse($task);
    }

    public function nextJob($collection_id, QueryContextManager $contextManager): JsonResponse|Response
    {
        $parts = explode('.', $collection_id);
        $parsed_id = $parts[0];
        $raw_type = $parts[1] ?? 'a';

        try {
            $task_type = TaskType::from($raw_type);
        } catch (\ValueError $e) {
            return $this->BadRequestResponseExtended("Invalid task type: '{$raw_type}'. Allowed types are: 'a', 'b'.");
        }


        $collection = Collection::where('pid', $parsed_id)->first();

        if (!$collection) {
            return $this->NotFoundResponse();
        }

        $nattemps = config('api.default_max_attemps', 3);
        $task = Task::where([
            'task_type' => $task_type,
            'completed_at' => null,
            'collection_id' => $collection->id
        ])
            ->where('attempts', '<', $nattemps)
            ->first();

        if (!$task) {
            error_log('returning no content');
            return $this->NoContentResponse();
        }

        $nextAttempts = $task->attempts + 1;
        $task->attempts     = $nextAttempts;
        $task->attempted_at = now();

        if ($nextAttempts === $nattemps) {
            $task->failed_at = now();
        }

        $task->save();
        $task->refresh()->load('submittedQuery');

        $submittedQuery = $task->submittedQuery;
        $rawQuery = $submittedQuery->definition;


        if ($task_type === TaskType::B) {
            $code = $rawQuery['code'] ?? 'DEMOGRAPHICS';
            $allowedCodes = ['DEMOGRAPHICS', 'GENERIC', 'ICD-MAIN'];

            if (!in_array($code, $allowedCodes)) {
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

        if (!$translatedQuery) {
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

    public function receiveResult(Request $request, $task_pid, $collection_pid): JsonResponse
    {
        $status = $request->get('status');
        $message = $request->get('message');
        $queryResult = $request->get('queryResult');

        if (!is_array($queryResult) || !isset($queryResult['count']) || !is_numeric($queryResult['count'])) {
            return $this->BadRequestResponseExtended('Invalid or missing count in queryResult.');
        }

        $count = $queryResult['count'];


        error_log("\033[32m[RESULT RECEIVED]\033[0m Status: {$status}, Count: {$count}, Task PID: {$task_pid}");

        $task = Task::where(['pid' => $task_pid])->first();

        if (!$task) {
            return $this->NotFoundResponse();
        }

        $metadata = collect($queryResult)->except('count')->toArray();
        $storedFiles = [];

        foreach ($metadata['files'] ?? [] as $file) {

            if (!isset($file['file_data'])) {
                continue;
            }

            $fileName = $file['file_name'] ?? 'unknown';
            $fileType = $file['file_type'] ?? null;
            $fileDescription = $file['file_description'] ?? null;
            $fileDataBase64 = $file['file_data'];

            $decodedContent = base64_decode($fileDataBase64, true);

            if (!$decodedContent) {
                continue;
            }

            $hash = hash('sha256', $decodedContent);

            $path = sprintf('results/%s/%s-%s', $task->id, $hash, $fileName);

            //note: need to change this storage to a bucket??
            Storage::disk('local')->put($path, $decodedContent);

            $resultFile = ResultFile::create([
                'pid'             => $hash,
                'task_id'         => $task->id,
                'collection_id'   => $task->collection->id,
                'path'            => $path,
                'file_name'       => $fileName,
                'file_type'       => $fileType,
                'file_description' => $fileDescription,
                'status'          => ResultFile::STATUS_QUEUED,

            ]);

            ProcessDistributionFile::dispatch($resultFile->id);

            $storedFiles[] = [
                'file_name' => $fileName,
                'file_type' => $fileType,
                'file_description' => $fileDescription,
                'path' => $path,
            ];
        }

        $resultMetadata = !empty($storedFiles) ? ['parsed_files' => $storedFiles] : $metadata;

        Result::create([
            'task_id' => $task->id,
            'count' => (int) $count,
            'metadata' => $resultMetadata,
            'status' => $status,
            'message' => $message
        ]);

        $task->update([
            'completed_at' => now(),
            'failed_at' => null
        ]);
        $task->save();

        return $this->CreatedResponse([
            'message' => 'Result received successfully.',
        ]);
    }
}
