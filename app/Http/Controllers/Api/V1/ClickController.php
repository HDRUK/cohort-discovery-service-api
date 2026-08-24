<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Rules\IdOrUuid;
use App\Services\Activity\ActivityLogger;
use App\Traits\Responses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     *             @OA\Property(property="description", type="string", nullable=true, description="Optional free-form note, max 500 chars", example="User followed the collection link on the results page")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Click logged (or ignored as a duplicate)"),
     *     @OA\Response(response=403, description="Request did not originate from an allowed browser origin"),
     *     @OA\Response(response=404, description="Subject not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=429, description="Too many requests"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function store(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        // subject_id may arrive as a JSON number (42) or string ("42"/uuid).
        // Normalise to a string so IdOrUuid and ctype_digit behave (ctype_digit
        // misreads a raw integer as an ASCII codepoint).
        if (is_scalar($request->input('subject_id'))) {
            $request->merge(['subject_id' => (string) $request->input('subject_id')]);
        }

        // Outside the try: a ValidationException must reach Laravel's handler
        // as a 422 rather than being swallowed by the catch-all below.
        $validated = $request->validate([
            // Letters/underscores only: this becomes an App\Models class name, so
            // the charset must not allow namespace separators or other trickery.
            'subject_type' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_]+$/'],
            'subject_id' => ['required', new IdOrUuid()],
            'action' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            // Resolve the subject dynamically: task -> App\Models\Task. Any model
            // is accepted (there is no allowlist by design); the is_subclass_of
            // guard just keeps a bogus type from fatalling, returning 404 instead.
            $class = 'App\\Models\\'.Str::studly($validated['subject_type']);

            if (! is_subclass_of($class, Model::class)) {
                return $this->NotFoundResponse();
            }

            $subject = $class::whereIdOrPid($validated['subject_id'])->first();

            if (! $subject) {
                return $this->NotFoundResponse();
            }

            // Suppress duplicate/spammed identical clicks within a short window.
            $causerKey = $request->user()?->id ?: $request->ip();
            $dedupKey = "click:{$causerKey}:{$validated['subject_type']}:{$subject->getKey()}:{$validated['action']}";

            if (! Cache::add($dedupKey, true, config('clicks.dedup_seconds', 5))) {
                return $this->OKResponse(null);
            }

            $activityLogger->custom(
                logName: 'clicks',
                event: $validated['action'],
                subject: $subject,
                properties: ['subject_type' => $validated['subject_type']],
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
