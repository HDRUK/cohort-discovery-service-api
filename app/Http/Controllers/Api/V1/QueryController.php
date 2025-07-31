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
        $queries = Query::with([
            'tasks.collection.demographics',
            'tasks.result'
        ])
            ->whereHas('tasks', function ($query) {
                $query->where('task_type', TaskType::A);
            })
            ->orderBy('created_at', 'desc')
            ->get();
        return $this->OKResponse($queries);
    }

    public function getQuery($query_pid)
    {
        $query = Query::with(['tasks.collection.demographics', 'tasks.result'])->where('pid', $query_pid)->first();

        if (!$query) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($query);
    }
}
