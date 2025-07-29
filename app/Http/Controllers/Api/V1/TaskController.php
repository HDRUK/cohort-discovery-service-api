<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Result;
use App\Models\Task;
use App\Services\QueryContext\QueryContextManager;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use App\Traits\Responses;

class TaskController extends Controller
{
    use Responses;

    public function submitQueryAndCreateTasks(Request $request)
    {
        $validated = [];
        try {
            $validated = $request->validate([
                'name' => 'required|string',
                'definition' => 'required|array',
                'collection_filter' => 'nullable|array',
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
        $parsed_id = explode('.', $collection_id)[0];
        //to-do, implement handling task type ([1] in collection_id array)
        $collection = Collection::where('pid', $parsed_id)->first();

        if (!$collection) {
            return $this->NotFoundResponse();
        }

        $query = Task::where([
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

        error_log(json_encode($translatedQuery, JSON_PRETTY_PRINT));

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

        Result::create([
            'task_id' => $task->id,
            'count' => $count,
            'metadata' => [],
        ]);

        return $this->CreatedResponse([
            'message' => 'Result received successfully.',
        ]);
    }
}
