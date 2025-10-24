<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\ModelBackedRequest;
use App\Enums\TaskType;
use App\Jobs\RunBeaconTask;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use App\Services\QueryContext\QueryContextType;
use App\Services\Submitters\QuerySubmissionService;
use App\Http\Controllers\Controller;

class QueryController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function index(ModelBackedRequest $request): JsonResponse
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

    public function show(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::with(['tasks.collection.size', 'tasks.result'])
                ->where('id', $key)
                ->orWhere('pid', $key)
                ->firstOrFail();

            if (Gate::denies('view', $query)) {
                return  $this->ForbiddenResponse();
            }

            return $this->OKResponse($query);
        } catch (\Throwable $e) {
            return $this->NotFoundResponse();
        }
    }

    public function store(ModelBackedRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::create([
                'pid' => Str::uuid(),
                'name' => $validated['name'],
                'definition' => $validated['definition'],
                'user_id' => Auth::id(),
            ]);

            $result = app(QuerySubmissionService::class)
                ->handle($validated, Auth::id());

            return $this->CreatedResponse($result);
        } catch (\Throwable $e) {
            \Log::error('QueryController@store - failed: ' . json_encode($validated));
            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function update(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::where('id', $key)
                ->orWhere('pid', $key)
                ->firstOrFail();
            if ($query->update($validated)) {
                return $this->OKResponse($query);
            }

            return $this->ErrorResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@update - failed: ' .
                json_encode($validated) . ' (exception: ' .
                $e->getMessage() . ')');
            return $this->NotFoundResponse();
        }
    }

    public function destroy(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::where('id', $key)
                        ->orWhere('pid', $key)
                        ->firstOrFail();
            if ($query->delete()) {
                return $this->OKResponse([]);
            }

            return $this->ErrorResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@destroy/' . $validated['id'] . ' - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');
            return $this->NotFoundResponse();
        }
    }

    // public function duplicateAndReRun(ModelBackedRequest $request, int $id): JsonResponse
    // {
    //     $request->merge(['id' => $id]);
    //     $validated = $request->validated();

    //     try {
    //         $query = Query::findOrFail($validated['id']);

    //     } catch (\Throwable $e) {
    //         \Log::error('QueryController@duplicateAndReRun/' . $validated['id'] . ' - failed: ' .
    //             json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');
    //         return $this->NotFoundResponse();
    //     }
    // }

    // OLD

    public function getQueries(): JsonResponse
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

    public function getLatestQuery(): JsonResponse
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


    public function getQuery($query_pid): JsonResponse
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

    public function submitQueryAndCreateTasks(Request $request): JsonResponse
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
            'pid' => Str::uuid(),
            'name' => $validated['name'],
            'definition' => $validated['definition'],
            'user_id' => Auth::id(),
        ]);

        $collections = Collection::query();

        if (!empty($validated['collection_filter'])) {
            $collections->whereIn('pid', $validated['collection_filter']);
        }

        $collections = $collections->select(['id', 'type'])->get();

        $tasks = [];

        foreach ($collections as $collection) {
            $collectionId = $collection->id;
            $type = $collection->type;
            $task = Task::create([
                'pid' => Str::uuid(),
                'query_id' => $query->id,
                'collection_id' => $collectionId,
                'created_at' => now(),
                'task_type' => $validated['task_type'],
            ]);

            if ($type === QueryContextType::Beacon) {
                RunBeaconTask::dispatch($task);
            }

            $tasks[] = $task;
        }

        return $this->CreatedResponse([
            'query_pid' => $query->pid,
            'task_count' => count($tasks),
            'task_pids' => collect($tasks)->pluck('pid'),
        ]);
    }
}
