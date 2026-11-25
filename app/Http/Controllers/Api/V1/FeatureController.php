<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workgroup;
use App\Services\Features\FeatureAdminService;
use App\Services\Features\FeatureResolver;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FeatureController extends Controller
{
    use Responses;

    public function __construct(
        private FeatureResolver $resolver,
        private FeatureAdminService $admin
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->OKResponse($this->resolver->all(Auth::user()));
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        if (! $this->admin->exists($name)) {
            return $this->NotFoundResponse();
        }

        try {
            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        $this->admin->setGlobal($name, (bool) $validated['enabled']);

        return $this->OKResponse([]);
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
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        try {
            $overrides = collect($this->admin->overrides());

            $users = User::whereIn('id', $overrides->flatMap(fn (array $flag) => array_keys($flag['users']))->unique())
                ->orderBy('name')
                ->get(['id', 'name', 'email']);
            $workgroups = Workgroup::whereIn('id', $overrides->flatMap(fn (array $flag) => array_keys($flag['workgroups']))->unique())
                ->orderBy('name')
                ->get(['id', 'name']);

            return $this->OKResponse(
                $overrides
                    ->map(fn (array $flag, string $name) => [
                        'feature' => $name,
                        'global' => $flag['global'],
                        'overridable' => $flag['overridable'],
                        'users' => $users
                            ->filter(fn (User $user) => array_key_exists($user->id, $flag['users']))
                            ->map(fn (User $user) => [
                                'id' => $user->id,
                                'name' => $user->name,
                                'email' => $user->email,
                                'enabled' => $flag['users'][$user->id],
                            ])
                            ->values()
                            ->all(),
                        'workgroups' => $workgroups
                            ->filter(fn (Workgroup $workgroup) => array_key_exists($workgroup->id, $flag['workgroups']))
                            ->map(fn (Workgroup $workgroup) => [
                                'id' => $workgroup->id,
                                'name' => $workgroup->name,
                                'enabled' => $flag['workgroups'][$workgroup->id],
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all()
            );
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
    public function replaceOverrides(Request $request, string $name): JsonResponse
    {
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        if (! $this->admin->exists($name)) {
            return $this->NotFoundResponse();
        }

        if (! $this->resolver->isOverridable($name)) {
            return $this->ValidationErrorResponse(['feature' => ['This flag can only be set globally.']]);
        }

        try {
            $validated = $request->validate([
                'users' => ['present', 'array'],
                'users.*.id' => ['required', 'integer', 'distinct', 'exists:users,id'],
                'users.*.enabled' => ['required', 'boolean'],
                'workgroups' => ['present', 'array'],
                'workgroups.*.id' => ['required', 'integer', 'distinct', 'exists:workgroups,id'],
                'workgroups.*.enabled' => ['required', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        try {
            $this->admin->replaceOverrides($name, $validated['users'], $validated['workgroups']);

            return $this->OKResponse([]);
        } catch (\Throwable $e) {
            Log::error('FeatureController@replaceOverrides - failed (exception: '.$e->getMessage().')');

            return $this->ErrorResponse();
        }
    }

    private function isAdmin(): bool
    {
        return (bool) Auth::user()?->hasRole('admin');
    }
}
