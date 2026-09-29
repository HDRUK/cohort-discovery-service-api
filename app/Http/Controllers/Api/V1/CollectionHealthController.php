<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Services\Activity\ActivityLogger;
use App\Services\Collections\CollectionHealthService;
use App\Traits\Responses;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="Collection Health",
 *     description="Liveness telemetry for collection hosts, derived from their task polling"
 * )
 */
class CollectionHealthController extends Controller
{
    use AuthorizesRequests;
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/collections/{id}/health",
     *     summary="Binned ping history for a collection host",
     *     description="Collection hosts prove they are alive by polling for work. This endpoint
     *         returns how many polls ('pings') arrived per bin, split by task type - 'a' for cohort
     *         query jobs, 'b' for distributions jobs - plus a derived summary per task type.
     *
     *         Every bin carries an average pings-per-minute rate as well as the raw sum, so the
     *         numbers mean the same thing at every bin width: 'per_minute' is the series to chart,
     *         and the bin is a resolution control rather than a scale change. The final bin of a
     *         range is in progress, so it is divided by the minutes elapsed so far rather than by
     *         the full bin width.
     *
     *         The series is zero-filled across every bin in the range, so a silent host shows as
     *         a run of zeros rather than as missing entries.
     *
     *         Two ways to pick the range. 'window' is a duration back from now and returns exactly
     *         that many bins ending at the current, partial bin - so window=1h with minute bins is
     *         60 bins. 'from'/'to' is an explicit span and returns every bin from the one containing
     *         'from' to the one containing 'to', both inclusive. 'from' wins if both are supplied.
     *
     *         Requests whose bin and range would produce more than COLLECTION_HEALTH_MAX_BINS bins
     *         are rejected with a 422 rather than served.",
     *     tags={"Collection Health"},
     *
     *     @OA\Parameter(
     *         name="id", in="path", required=true,
     *         description="Collection integer id or UUID pid",
     *         @OA\Schema(type="string", example="c9f0f895-fb98-4ebd-9b1e-0f2a0b9a3f11")
     *     ),
     *     @OA\Parameter(
     *         name="bin", in="query", required=false,
     *         description="Bin width. Either a named unit - minute, hour, day, week, month - or a
     *             positive multiple of m, h, d or w for a custom width, such as 10m, 6h, 2d or 4w.
     *             Custom widths are aligned to an absolute grid, so a 10m bin always starts at :00,
     *             :10, :20 and so on. Months have no multiple form. Defaults to minute.",
     *         @OA\Schema(type="string", example="10m", default="minute")
     *     ),
     *     @OA\Parameter(
     *         name="window", in="query", required=false,
     *         description="Duration back from now: a positive integer followed by m, h, d or w. Defaults to 1h. Ignored when 'from' is supplied.",
     *         @OA\Schema(type="string", example="24h", default="1h")
     *     ),
     *     @OA\Parameter(
     *         name="from", in="query", required=false,
     *         description="Start of an explicit range (ISO-8601). Overrides 'window'.",
     *         @OA\Schema(type="string", format="date-time", example="2026-08-30T00:00:00Z")
     *     ),
     *     @OA\Parameter(
     *         name="to", in="query", required=false,
     *         description="End of an explicit range (ISO-8601). Defaults to now.",
     *         @OA\Schema(type="string", format="date-time", example="2026-09-01T00:00:00Z")
     *     ),
     *
     *     @OA\Response(
     *         response=200, description="Binned ping history",
     *
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="success"),
     *             @OA\Property(
     *                 property="data", type="object",
     *                 @OA\Property(property="collection_id", type="integer", example=12),
     *                 @OA\Property(property="bin", type="string", example="minute"),
     *                 @OA\Property(property="from", type="string", format="date-time", description="Start of the first bin, inclusive", example="2026-09-01T09:01:00Z"),
     *                 @OA\Property(property="to", type="string", format="date-time", description="End of the range, EXCLUSIVE - the start of the bin after the last one returned. Note this differs from /task-history, where 'to' is inclusive.", example="2026-09-01T10:01:00Z"),
     *                 @OA\Property(
     *                     property="summary", type="object",
     *                     description="Keyed by task type, 'a' and 'b'",
     *                     @OA\Property(
     *                         property="a", type="object",
     *                         @OA\Property(property="last_ping_at", type="string", format="date-time", nullable=true, example="2026-09-01T09:59:41Z"),
     *                         @OA\Property(property="pings", type="integer", example=706),
     *                         @OA\Property(property="per_minute", type="number", format="float", nullable=true, description="Minute-weighted average pings per minute across the whole range: pings / minutes. Null only when no minute of the range has elapsed.", example=11.767),
     *                         @OA\Property(property="minutes", type="integer", description="Total elapsed minutes covered by the range", example=60),
     *                         @OA\Property(property="silent_minutes", type="integer", description="Minutes within the range that had zero pings", example=3),
     *                         @OA\Property(property="bins", type="integer", example=60),
     *                         @OA\Property(property="empty_bins", type="integer", example=1),
     *                         @OA\Property(property="longest_gap_bins", type="integer", example=1)
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="series", type="object",
     *                     description="Keyed by task type, 'a' and 'b'. Chart 'per_minute' - it is the same
     *                         quantity at every bin width, so changing the bin re-shapes the line without
     *                         re-scaling the axis. 'n' is the raw sum for that bin.",
     *                     @OA\Property(
     *                         property="a", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="bin", type="string", format="date-time", example="2026-09-01T09:59:00Z"),
     *                             @OA\Property(property="n", type="integer", description="Pings summed across the bin", example=706),
     *                             @OA\Property(property="minutes", type="integer", description="Elapsed minutes this bin covers. Equals the bin width except for the final, in-progress bin, and is 0 for a bin wholly in the future.", example=60),
     *                             @OA\Property(property="silent_minutes", type="integer", description="Minutes within the bin that had zero pings - the gap signal that stays meaningful at coarse bin widths", example=3),
     *                             @OA\Property(property="per_minute", type="number", format="float", nullable=true, description="n / minutes, rounded to 3dp. Null when minutes is 0.", example=11.767)
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=403, description="Not an admin, and not attached to the collection's custodian"),
     *     @OA\Response(response=404, description="Collection not found"),
     *     @OA\Response(response=422, description="Validation error, or the bin and range would produce too many bins"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function show(
        Request $request,
        string $id,
        ActivityLogger $activityLogger,
        CollectionHealthService $health
    ): JsonResponse {
        $validated = $request->validate([
            'bin' => ['sometimes', 'string', 'max:16'],
            'window' => ['sometimes', 'string', 'max:16'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
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

        $window = $health->resolveWindow($validated);

        try {
            $data = $health->health($collection->id, $window);

            $activityLogger->viewed('collection_health', $collection, [
                'filters' => $request->query(),
            ]);

            return $this->OKResponse($data);
        } catch (\Throwable $e) {
            Log::error('CollectionHealthController@show - failed: '.$e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }
}
