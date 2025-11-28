<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QueryType;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\Collection;
use App\Traits\JobCreation;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;

class DistributionController extends Controller
{
    use JobCreation;
    use Responses;

    public function manuallyTriggeredRun(ModelBackedRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $collection = Collection::findOrFail($validated['collection_id']);
            $query = $this->createQuery(
                'manual-run-'.str_replace(' ', '-', $collection->name),
                QueryType::DEMOGRAPHICS
            );

            $task = $this->createTask(
                $query,
                $collection->id,
                TaskType::B
            );

            return $this->OKResponse([
                'query' => [
                    'id' => $query->id,
                    'created_at' => $query->created_at,
                ],
                'task' => [
                    'id' => $task->id,
                    'created_at' => $task->created_at,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->NotFoundResponse();
        }
    }
}
