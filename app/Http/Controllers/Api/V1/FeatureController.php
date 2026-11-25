<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Activity\ActivityLogger;
use App\Services\Features\FeatureAdminService;
use App\Services\Features\FeatureResolver;
use App\Traits\Responses;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

class FeatureController extends Controller
{
    use AuthorizesRequests;
    use Responses;

    public function __construct(
        private FeatureResolver $resolver,
        private FeatureAdminService $adminService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->OKResponse($this->resolver->all());
    }

    public function update(Request $request, string $name, ActivityLogger $activityLogger): JsonResponse
    {
        try {
            $this->authorize('manage', Feature::class);

            if (! $this->adminService->exists($name)) {
                return $this->NotFoundResponse();
            }

            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
            ]);

            $this->adminService->setGlobal($name, (bool) $validated['enabled']);

            return $this->OKResponse([]);
        } catch (AuthorizationException) {
            return $this->ForbiddenResponse();
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        } catch (\Throwable $e) {
            Log::error('FeatureController@update - failed (exception: '.$e->getMessage().')');

            $activityLogger->failed('feature_flags', null, $e, ['feature' => $name], 'feature_flag_update_failed');

            return $this->ErrorResponse();
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/features/overrides",
     *     summary="List every feature flag with its global value and per-user and per-workgroup overrides",
     *     tags={"Features"},
     *
     *     @OA\Response(response=200, description="Flags with their overrides"),
     *     @OA\Response(response=403, description="Admin only"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function overrides(Request $request): JsonResponse
    {
        try {
            $this->authorize('manage', Feature::class);

            return $this->OKResponse($this->adminService->overridesWithSubjects());
        } catch (AuthorizationException) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            Log::error('FeatureController@overrides - failed (exception: '.$e->getMessage().')');

            return $this->ErrorResponse();
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/features/{name}/overrides",
     *     summary="Replace the per-user and per-workgroup overrides of a feature flag",
     *     tags={"Features"},
     *
     *     @OA\Parameter(name="name", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"users", "workgroups"},
     *
     *             @OA\Property(property="users", type="array", @OA\Items(type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="enabled", type="boolean"))),
     *             @OA\Property(property="workgroups", type="array", @OA\Items(type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="enabled", type="boolean")))
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Overrides replaced"),
     *     @OA\Response(response=403, description="Admin only"),
     *     @OA\Response(response=404, description="Unknown flag"),
     *     @OA\Response(response=422, description="Validation error, or the flag can only be set globally"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function replaceOverrides(Request $request, string $name, ActivityLogger $activityLogger): JsonResponse
    {
        try {
            $this->authorize('manage', Feature::class);

            if (! $this->adminService->exists($name)) {
                return $this->NotFoundResponse();
            }

            if (! $this->resolver->isOverridable($name)) {
                return $this->ValidationErrorResponse(['feature' => ['This flag can only be set globally.']]);
            }

            $validated = $request->validate([
                'users' => ['present', 'array'],
                'users.*.id' => ['required', 'integer', 'distinct', 'exists:users,id'],
                'users.*.enabled' => ['required', 'boolean'],
                'workgroups' => ['present', 'array'],
                'workgroups.*.id' => ['required', 'integer', 'distinct', 'exists:workgroups,id'],
                'workgroups.*.enabled' => ['required', 'boolean'],
            ]);

            $this->adminService->replaceOverrides($name, $validated['users'], $validated['workgroups']);

            return $this->OKResponse([]);
        } catch (AuthorizationException) {
            return $this->ForbiddenResponse();
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        } catch (\Throwable $e) {
            Log::error('FeatureController@replaceOverrides - failed (exception: '.$e->getMessage().')');

            $activityLogger->failed('feature_flags', null, $e, ['feature' => $name], 'feature_flag_overrides_update_failed');

            return $this->ErrorResponse();
        }
    }
}
