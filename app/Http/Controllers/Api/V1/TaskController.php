<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Distribution;
use App\Models\Query;
use App\Models\Result;
use App\Models\Task;
use App\Services\QueryContext\QueryContextManager;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Validation\Rules\Enum;


class TaskController extends Controller
{
    use Responses;
    use HelperFunctions;


    public function getTasks()
    {
        //to-do: only get user tasks...
        $tasks = Task::all();
        return $this->OKResponse($tasks);
    }


    public function getTask($task_pid)
    {
        $task = Task::with(['submittedQuery', 'collection'])->where('pid', $task_pid)->first();

        if (!$task) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($task);
    }

    public function submitQueryAndCreateTasks(Request $request)
    {
        $validated = [];
        try {
            $validated = $request->validate([
                'name' => 'required|string',
                'definition' => 'required|array',
                'collection_filter' => 'nullable|array',
                'task_type' => ['required', new Enum(TaskType::class)],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        $query = Query::create([
            'name' => $validated['name'],
            'definition' => $validated['definition'],
        ]);


        $collections = Collection::query();

        if (!empty($validated['collection_filter'])) {
            $collections->whereIn('pid', $validated['collection_filter']);
        }

        $collections = $collections->pluck('id');

        $tasks = [];

        foreach ($collections as $collectionId) {
            $tasks[] = Task::create([
                'query_id' => $query->id,
                'collection_id' => $collectionId,
                'created_at' => now(),
                'task_type' => $validated['task_type'],
            ]);
        }

        return $this->CreatedResponse([
            'query_pid' => $query->pid,
            'task_count' => count($tasks),
            'task_pids' => collect($tasks)->pluck('pid'),
        ]);
    }

    public function nextJob($collection_id, QueryContextManager $contextManager)
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

        $query = Task::where([
            'task_type' => $task_type,
            'completed_at' => null,
            'collection_id' => $collection->id
        ]);

        $task = $query->with('submittedQuery')->first();

        if (!$task) {
            error_log('returning no content');
            return $this->NoContentResponse();
        }

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

    public function receiveResult(Request $request, $task_pid, $collection_pid)
    {
        $queryResult = $request->get('queryResult');

        if (!is_array($queryResult) || !isset($queryResult['count']) || !is_numeric($queryResult['count'])) {
            return $this->BadRequestResponseExtended('Invalid or missing count in queryResult.');
        }

        $count = $queryResult['count'];

        error_log("\033[32m[RESULT RECEIVED]\033[0m Count: {$count}, Task PID: {$task_pid}");

        $task = Task::where(['pid' => $task_pid])->first();

        if (!$task) {
            return $this->NotFoundResponse();
        }

        $task->update(['completed_at' => now()]);
        $task->save();

        $metadata = collect($queryResult)->except('count')->toArray();
        $parsedFiles = [];

        foreach ($metadata['files'] ?? [] as $file) {
            if (!isset($file['file_data'])) {
                continue;
            }

            $fileDataBase64 = $file['file_data'];
            $decodedContent = base64_decode($fileDataBase64);

            if (!$decodedContent) {
                continue;
            }

            $parsed = $this->tsvToArray($decodedContent);

            $fileName = $file['file_name'] ?? 'unknown';
            $fileType = $file['file_type'] ?? null;
            $fileDescription = $file['file_description'] ?? null;

            if ($fileName === 'demographics.distribution') {
                foreach ($parsed as $row) {
                    if (is_null($row['CODE']) || !isset($row['COUNT'])) {
                        continue;
                    }
                    Distribution::create([
                        'collection_id' => $task->collection->id,
                        'task_id'       => $task->id,
                        'category'      => $row['CATEGORY'],
                        'name'          => $row['CODE'],
                        'description'   => $row['DESCRIPTION'] ?? null,
                        'count'         => $row['COUNT'],
                        'q1'            => $row['Q1'] ?? null,
                        'q3'            => $row['Q3'] ?? null,
                        'min'           => $row['MIN'] ?? null,
                        'max'           => $row['MAX'] ?? null,
                        'mean'          => $row['MEAN'] ?? null,
                        'median'        => $row['MEDIAN'] ?? null,
                    ]);

                    if (!isset($row['ALTERNATIVES'])) {
                        continue;
                    }
                    $alternatives = $row['ALTERNATIVES'];
                    $segments = explode('^', trim($alternatives, '^'));

                    foreach ($segments as $segment) {
                        if (strpos($segment, '|') !== false) {
                            [$name, $count] = explode('|', $segment);

                            Distribution::create([
                                'collection_id'  => $task->collection->id,
                                'task_id'        => $task->id,
                                'category'       => $row['CATEGORY'],
                                'name'           => (string) $name,
                                'description'    => (string) $name,
                                'count'          => (int) $count,
                            ]);
                        }
                    }
                }
            }

            $parsedFiles[] = [
                'file_name' => $fileName,
                'file_type' => $fileType,
                'file_description' => $fileDescription,
            ];
        }

        $resultMetadata = [];

        if (!empty($parsedFiles)) {
            $resultMetadata['parsed_files'] = $parsedFiles;
        } else {
            $resultMetadata = $metadata;
        }

        Result::create([
            'task_id' => $task->id,
            'count' => $count,
            'metadata' => $resultMetadata,
        ]);

        return $this->CreatedResponse([
            'message' => 'Result received successfully.',
        ]);

        //return $this->ErrorResponse()
    }
}
