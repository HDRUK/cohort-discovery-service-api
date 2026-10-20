<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Services\Activity\ActivityLogger;
use App\Services\Collections\CollectionTaskHistoryService;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="Collection Task History",
 *     description="Execution history of the tasks queued against a collection"
 * )
 */
class CollectionTaskHistoryController extends Controller
{
    use AuthorizesRequests;
    use HelperFunctions;
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/collections/{id}/task-history",
     *     summary="Task execution history for a collection",
     *     description="What actually ran against this collection over a time range, and how it went.
     *         One entry per task, newest first, each carrying every attempt made at it - the worker
     *         that claimed it, when, and how long the attempt took.
     *
     *         Alongside the page is a summary computed over the whole range rather than the page, so
     *         the totals do not change as you page through. It covers outcome counts, retry counts and
     *         the distribution of run durations, including p50 and p95 - the tail is what makes a
     *         collection feel slow, and one long outlier barely moves the mean.
     *
     *         Two ways to pick the range. 'window' is a duration back from now and defaults to 1d.
     *         'from'/'to' is an explicit span, inclusive at both ends; 'from' overrides 'window'.
     *
     *         Task status is derived from the task's own timestamps: 'succeeded', 'failed',
     *         'in_flight' (claimed, no result yet) or 'pending' (queued, never claimed). Filtering by
     *         status or task type narrows the page and the summary together.
     *
     *         Restricted to admins and to users attached to the collection's custodian.",
     *     tags={"Collection Task History"},
     *
     *     @OA\Parameter(
     *         name="id", in="path", required=true,
     *         description="Collection integer id or UUID pid",
     *         @OA\Schema(type="string", example="c9f0f895-fb98-4ebd-9b1e-0f2a0b9a3f11")
     *     ),
     *     @OA\Parameter(
     *         name="window", in="query", required=false,
     *         description="Duration back from now: a positive integer followed by m, h, d or w. Defaults to 1d. Ignored when 'from' is supplied.",
     *         @OA\Schema(type="string", example="7d", default="1d")
     *     ),
     *     @OA\Parameter(
     *         name="from", in="query", required=false,
     *         description="Start of an explicit range (ISO-8601), inclusive. Overrides 'window'.",
     *         @OA\Schema(type="string", format="date-time", example="2026-08-30T00:00:00Z")
     *     ),
     *     @OA\Parameter(
     *         name="to", in="query", required=false,
     *         description="End of an explicit range (ISO-8601), inclusive. Defaults to now.",
     *         @OA\Schema(type="string", format="date-time", example="2026-09-01T00:00:00Z")
     *     ),
     *     @OA\Parameter(
     *         name="task_type", in="query", required=false,
     *         description="Restrict to one task type - 'a' for cohort query jobs, 'b' for distributions jobs",
     *         @OA\Schema(type="string", enum={"a", "b"}, example="a")
     *     ),
     *     @OA\Parameter(
     *         name="status", in="query", required=false,
     *         description="Restrict to one derived task status",
     *         @OA\Schema(type="string", enum={"succeeded", "failed", "in_flight", "pending"}, example="failed")
     *     ),
     *     @OA\Parameter(
     *         name="include", in="query", required=false,
     *         description="Set to 'definition' to add each task's full query definition to its 'query' object. Omitted by default - definitions are large and this is a paginated list.",
     *         @OA\Schema(type="string", enum={"definition"}, example="definition")
     *     ),
     *     @OA\Parameter(
     *         name="per_page", in="query", required=false,
     *         description="Tasks per page, capped at 100",
     *         @OA\Schema(type="integer", example=25)
     *     ),
     *
     *     @OA\Response(
     *         response=200, description="Task history for the range",
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="success"),
     *             @OA\Property(
     *                 property="data", type="object",
     *                 @OA\Property(property="collection_id", type="integer", example=12),
     *                 @OA\Property(property="from", type="string", format="date-time", description="Start of the range, inclusive", example="2026-09-01T10:01:00Z"),
     *                 @OA\Property(property="to", type="string", format="date-time", description="End of the range, INCLUSIVE. Note this differs from /health, where 'to' is exclusive.", example="2026-09-02T10:01:00Z"),
     *                 @OA\Property(
     *                     property="summary", type="object",
     *                     description="Computed across the whole range, not just the page",
     *                     @OA\Property(property="tasks", type="integer", example=42),
     *                     @OA\Property(property="succeeded", type="integer", example=38),
     *                     @OA\Property(property="failed", type="integer", example=3),
     *                     @OA\Property(property="in_flight", type="integer", description="Claimed by a host, no result yet", example=1),
     *                     @OA\Property(property="pending", type="integer", description="Queued, never claimed", example=0),
     *                     @OA\Property(
     *                         property="task_types", type="object", description="Task count keyed by task type",
     *                         @OA\Property(property="a", type="integer", example=40),
     *                         @OA\Property(property="b", type="integer", example=2)
     *                     ),
     *                     @OA\Property(
     *                         property="attempts", type="object",
     *                         @OA\Property(property="total", type="integer", description="Attempts made across every task in the range", example=47),
     *                         @OA\Property(property="retried_tasks", type="integer", description="Tasks that took more than one attempt", example=4),
     *                         @OA\Property(property="max", type="integer", example=3)
     *                     ),
     *                     @OA\Property(
     *                         property="duration_ms", type="object",
     *                         description="Distribution of reported run durations. Every field but runs_measured is null when nothing in the range reported one.",
     *                         @OA\Property(property="runs_measured", type="integer", description="Runs that reported a duration - fewer than the attempts made, because a timed-out attempt never reports one", example=45),
     *                         @OA\Property(property="min", type="integer", nullable=true, example=120),
     *                         @OA\Property(property="avg", type="integer", nullable=true, example=1150),
     *                         @OA\Property(property="p50", type="integer", nullable=true, example=900),
     *                         @OA\Property(property="p95", type="integer", nullable=true, example=4200),
     *                         @OA\Property(property="max", type="integer", nullable=true, example=8800)
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="tasks", type="object", description="Paginated tasks, newest first",
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=25),
     *                     @OA\Property(property="total", type="integer", example=42),
     *                     @OA\Property(
     *                         property="data", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="pid", type="string", example="c9f0f895-fb98-4ebd-9b1e-0f2a0b9a3f11"),
     *                             @OA\Property(property="task_type", type="string", example="a"),
     *                             @OA\Property(property="status", type="string", enum={"succeeded", "failed", "in_flight", "pending"}, example="succeeded"),
     *                             @OA\Property(property="attempts", type="integer", example=2),
     *                             @OA\Property(property="created_at", type="string", format="date-time", example="2026-09-02T09:58:00Z"),
     *                             @OA\Property(property="attempted_at", type="string", format="date-time", nullable=true, example="2026-09-02T09:58:04Z"),
     *                             @OA\Property(property="completed_at", type="string", format="date-time", nullable=true, example="2026-09-02T09:58:06Z"),
     *                             @OA\Property(property="failed_at", type="string", format="date-time", nullable=true, example=null),
     *                             @OA\Property(property="queued_for_ms", type="integer", nullable=true, description="Created until first claimed - queue latency rather than execution time", example=4000),
     *                             @OA\Property(property="duration_ms", type="integer", nullable=true, description="Duration of the attempt that settled the task", example=1900),
     *                             @OA\Property(property="total_duration_ms", type="integer", nullable=true, description="Duration summed across every attempt", example=3400),
     *                             @OA\Property(
     *                                 property="query", type="object", nullable=true,
     *                                 description="The query this task ran. Null once that query has been soft-deleted - the task survives, but the query is hidden by the soft-delete scope.",
     *                                 @OA\Property(property="pid", type="string", example="a1b2c3d4-1111-2222-3333-444455556666"),
     *                                 @OA\Property(property="name", type="string", nullable=true, example="T2D over 60"),
     *                                 @OA\Property(property="query_type", type="string", nullable=true, example="cohort"),
     *                                 @OA\Property(property="definition", type="object", description="The full query definition. Present only when 'include=definition' was requested.")
     *                             ),
     *                             @OA\Property(
     *                                 property="runs", type="array", description="Every attempt, oldest first",
     *                                 @OA\Items(
     *                                     @OA\Property(property="attempt", type="integer", example=1),
     *                                     @OA\Property(property="worker_id", type="string", nullable=true, example="10.0.0.14"),
     *                                     @OA\Property(property="claimed_at", type="string", format="date-time", nullable=true, example="2026-09-02T09:58:04Z"),
     *                                     @OA\Property(property="started_at", type="string", format="date-time", nullable=true, example="2026-09-02T09:58:04Z"),
     *                                     @OA\Property(property="finished_at", type="string", format="date-time", nullable=true, example="2026-09-02T09:58:06Z"),
     *                                     @OA\Property(property="duration_ms", type="integer", nullable=true, example=1900),
     *                                     @OA\Property(property="result_status", type="string", nullable=true, description="Null for an attempt that never reported, such as one timed out by task cleanup", example="ok"),
     *                                     @OA\Property(property="error_class", type="string", nullable=true, example="Timeout"),
     *                                     @OA\Property(property="error_message", type="string", nullable=true, example="No result received within 300 seconds.")
     *                                 )
     *                             )
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=403, description="Not an admin, and not attached to the collection's custodian"),
     *     @OA\Response(response=404, description="Collection not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function index(
        Request $request,
        string $id,
        ActivityLogger $activityLogger,
        CollectionTaskHistoryService $history
    ): JsonResponse {
        $validated = $request->validate([
            'window' => ['sometimes', 'string', 'max:16'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'task_type' => ['sometimes', Rule::enum(TaskType::class)],
            'status' => ['sometimes', Rule::in(CollectionTaskHistoryService::STATUSES)],
            'include' => ['sometimes', Rule::in(['definition'])],
        ]);

        $collection = Collection::whereIdOrPid($id)->first();

        if (! $collection) {
            return $this->NotFoundResponse();
        }

        try {
            $this->authorize('view', $collection);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        }

        $range = $history->resolveRange($validated);

        try {
            $data = $history->history(
                $collection->id,
                $range,
                [
                    'task_type' => $validated['task_type'] ?? null,
                    'status' => $validated['status'] ?? null,
                ],
                $this->resolvePerPage(),
                ($validated['include'] ?? null) === 'definition'
            );

            $activityLogger->viewed('collection_task_history', $collection, [
                'filters' => $request->query(),
            ]);

            return $this->OKResponse($data);
        } catch (\Throwable $e) {
            Log::error('CollectionTaskHistoryController@index - failed: '.$e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }
}
