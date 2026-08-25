<?php

namespace App\Http\Controllers\Api\V1;

use Closure;
use App\Http\Controllers\Controller;
use App\Rules\IdOrUuid;
use App\Services\Activity\ActivityLogger;
use App\Traits\Responses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class ClickController extends Controller
{
    use Responses;

    /**
     * @OA\Post(
     *     path="/api/v1/clicks",
     *     summary="Record a user action (click) on a subject",
     *     description="Generic click/action tracker. The frontend names the subject by type
     *         and id-or-pid and supplies a free-form action label; the backend records an
     *         activity-log entry. By default the entry is attributed to the authenticated user;
     *         the 'click-tracking-anonymous' feature flag switches all entries to anonymous.
     *         Any authenticated caller may record a click on any existing subject - there is no
     *         ownership check.",
     *     tags={"Clicks"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"subject_type", "subject_id", "action"},
     *
     *             @OA\Property(property="subject_type", type="string", description="Name of the App\Models model, e.g. task, collection, query (resolved as App\Models\{StudlyName})", example="task"),
     *             @OA\Property(property="subject_id", type="string", description="Integer id or UUID pid of the subject", example="42"),
     *             @OA\Property(property="action", type="string", description="snake_case action label, max 64 chars", example="clicked_collection_link"),
     *             @OA\Property(property="description", type="string", nullable=true, description="Optional free-form note, max 500 chars", example="User followed the collection link on the results page"),
     *             @OA\Property(property="properties", type="object", nullable=true, description="Optional arbitrary JSON merged into the activity-log properties column, max 2000 bytes encoded. A 'subject_type' key is always overwritten by the resolved type.", example={"origin": "results_table", "position": 3})
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Click logged"),
     *     @OA\Response(response=404, description="Subject not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=429, description="Too many requests"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function store(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        // Must stay outside the try, or the catch-all below swallows the 422.
        $validated = $request->validate([
            'subject_type' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_]+$/'],
            'subject_id' => ['required', new IdOrUuid()],
            'action' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'properties' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail) {
                if (strlen((string) json_encode($value)) > 2000) {
                    $fail('The properties field must not exceed 2000 bytes of JSON.');
                }
            }],
        ]);

        try {
            $class = 'App\\Models\\'.Str::studly($validated['subject_type']);

            if (! is_subclass_of($class, Model::class)) {
                return $this->NotFoundResponse();
            }

            $subject = $class::whereIdOrPid($validated['subject_id'])->first();

            if (! $subject) {
                return $this->NotFoundResponse();
            }

            $activityLogger->custom(
                logName: 'clicks',
                event: $validated['action'],
                subject: $subject,
                properties: array_merge($validated['properties'] ?? [], ['subject_type' => $validated['subject_type']]),
                description: $validated['description'] ?? null,
                anonymous: Feature::active('click-tracking-anonymous'),
            );

            return $this->OKResponse(null);
        } catch (\Throwable $e) {
            Log::error('ClickController@store - failed (exception: '.$e->getMessage().')');

            return $this->ErrorResponse();
        }
    }
}
