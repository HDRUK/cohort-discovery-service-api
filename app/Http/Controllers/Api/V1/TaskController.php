<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Result;
use App\Models\Task;
use App\Services\QueryContext\QueryContextManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TaskController extends Controller
{
    public function nextJob($collection_id, QueryContextManager $contextManager)
    {
        $parsed_id = explode('.', $collection_id)[0];
        $collection = Collection::where('pid', $parsed_id)->first();

        $task = Task::where(
            [
                'completed_at' => null,
                'collection_id' => $collection->id
            ]
        )->with('submittedQuery')->first();

        if (!$task) {
            return response()->noContent();
        }

        $submittedQuery = $task->submittedQuery;
        $rawQuery = $submittedQuery->definition;


        $translatedQuery = null;
        try {
            $contextType = $collection->type;
            $translatedQuery = $contextManager->handle($rawQuery, $contextType);
        } catch (\ValueError $e) {
            return response()->json(['error' => 'Unsupported collection type'], 400);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        if (!$translatedQuery) {
            return response()->json(['error' => 'Context manager failed to translate query'], 400);
        }

        return response()->json([
            'task_id' => $task->id,
            'project' => 'unknown_project', //??
            'owner' => '1', //??
            'collection' => $collection->pid,
            'protocol_version' => 'v2', // ??
            'char_salt' => bin2hex(random_bytes(4)), //??
            'uuid' => $task->pid,
            'cohort' => $translatedQuery,
        ]);
    }


    public function receiveResult(Request $request, $task_pid, $collection_pid)
    {
        $queryResult = $request->get('queryResult');

        if (!is_array($queryResult) || !isset($queryResult['count']) || !is_numeric($queryResult['count'])) {
            return response()->json([
                'error' => 'Invalid or missing count in queryResult.',
            ], 400);
        }

        $count = $queryResult['count'];

        error_log("\033[32m[RESULT RECEIVED]\033[0m Count: {$count}, Task PID: {$task_pid}");

        $task = Task::where(['pid' => $task_pid])->first();
        $task->update(['completed_at' => now()]);


        Result::create([
            'task_id' => $task->id,
            'count' => $count,
            'metadata' => [],
        ]);

        return response()->json([
            'message' => 'Result received successfully.',
        ], 201);
    }
}
