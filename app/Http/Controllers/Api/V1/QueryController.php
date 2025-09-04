<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;


class QueryController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function getQueries()
    {
        $perPage = $this->resolvePerPage();
        $queries = Query::with([
            'tasks.collection.size',
            'tasks.result'
        ])
            ->where('user_id', Auth::id())
            ->whereHas('tasks', function ($query) {
                $query->where('task_type', TaskType::A);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        return $this->OKResponse($queries);
    }

    public function getLatestQuery()
    {
        $query = Query::with(['tasks.collection.size', 'tasks.result'])
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$query) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($query);
    }


    public function getQuery($query_pid)
    {
        $query = Query::with(['tasks.collection.size', 'tasks.result'])
            ->where('pid', $query_pid)
            ->first();

        if (!$query) {
            return $this->NotFoundResponse();
        }

        if (Gate::denies('view', $query)) {
            return  $this->ForbiddenResponse();
        }

        return $this->OKResponse($query);
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
            'user_id' => Auth::id(),
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
}
