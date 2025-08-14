<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Query;

use App\Traits\Responses;
use App\Traits\HelperFunctions;



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
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$query) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($query);
    }


    public function getQuery($query_pid)
    {
        $query = Query::with(['tasks.collection.size', 'tasks.result'])->where('pid', $query_pid)->first();

        if (!$query) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($query);
    }
}
