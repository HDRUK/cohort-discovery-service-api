<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workgroup;
use App\Services\Activity\ActivityLogger;
use App\Services\Features\FeatureResolver;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

class FeatureController extends Controller
{
    use Responses;

    public function __construct(private FeatureResolver $resolver)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $effective = $this->resolver->allFor(Auth::user());

        $data = $this->featureNames()
            ->mapWithKeys(fn (string $name) => [$name => $effective[$name] ?? false])
            ->all();

        return $this->OKResponse($data);
    }

    public function global(Request $request): JsonResponse
    {
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        $data = $this->featureNames()
            ->mapWithKeys(fn (string $name) => [
                $name => (bool) Feature::for(null)->value($name),
            ])
            ->all();

        return $this->OKResponse($data);
    }

    public function update(Request $request, string $name, ActivityLogger $activityLogger): JsonResponse
    {
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        try {
            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        $before = (bool) Feature::for(null)->value($name);
        $after = (bool) $validated['enabled'];

        if ($after) {
            Feature::for(null)->activate($name);
        } else {
            Feature::for(null)->deactivate($name);
        }

        $activityLogger->custom('feature_flags', 'updated', null, [
            'feature' => $name,
            'before' => ['enabled' => $before],
            'after' => ['enabled' => $after],
        ]);

        return $this->OKResponse([]);
    }

    public function overrides(Request $request): JsonResponse
    {
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        return $this->OKResponse(
            collect(config('features.scopable', []))
                ->map(fn (string $name) => $this->overridesFor($name))
                ->all()
        );
    }

    public function setUserOverride(Request $request, string $name, int $userId, ActivityLogger $activityLogger): JsonResponse
    {
        return $this->setOverride($request, $name, User::find($userId), $activityLogger);
    }

    public function clearUserOverride(Request $request, string $name, int $userId, ActivityLogger $activityLogger): JsonResponse
    {
        return $this->clearOverride($name, User::find($userId), $activityLogger);
    }

    public function setWorkgroupOverride(Request $request, string $name, int $workgroupId, ActivityLogger $activityLogger): JsonResponse
    {
        return $this->setOverride($request, $name, Workgroup::find($workgroupId), $activityLogger);
    }

    public function clearWorkgroupOverride(Request $request, string $name, int $workgroupId, ActivityLogger $activityLogger): JsonResponse
    {
        return $this->clearOverride($name, Workgroup::find($workgroupId), $activityLogger);
    }

    private function setOverride(Request $request, string $name, User|Workgroup|null $subject, ActivityLogger $activityLogger): JsonResponse
    {
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        if (! $this->resolver->isScopable($name)) {
            return $this->notScopableResponse();
        }

        if (! $subject) {
            return $this->NotFoundResponse();
        }

        try {
            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        $before = $this->resolver->overrideFor($name, $subject);
        $after = (bool) $validated['enabled'];

        if ($after) {
            Feature::for($subject)->activate($name);
        } else {
            Feature::for($subject)->deactivate($name);
        }

        $this->logOverride($activityLogger, 'override_set', $name, $subject, $before, $after);

        return $this->OKResponse([]);
    }

    private function clearOverride(string $name, User|Workgroup|null $subject, ActivityLogger $activityLogger): JsonResponse
    {
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        if (! $this->resolver->isScopable($name)) {
            return $this->notScopableResponse();
        }

        if (! $subject) {
            return $this->NotFoundResponse();
        }

        $before = $this->resolver->overrideFor($name, $subject);

        if ($before === null) {
            return $this->OKResponse([]);
        }

        Feature::for($subject)->forget($name);

        $this->logOverride($activityLogger, 'override_cleared', $name, $subject, $before, null);

        return $this->OKResponse([]);
    }

    private function overridesFor(string $name): array
    {
        $userOverrides = $this->resolver->overridesOf($name, User::class);
        $workgroupOverrides = $this->resolver->overridesOf($name, Workgroup::class);

        return [
            'feature' => $name,
            'global' => (bool) Feature::for(null)->value($name),
            'users' => User::whereIn('id', array_keys($userOverrides))
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'enabled' => $userOverrides[$user->id],
                ])
                ->all(),
            'workgroups' => Workgroup::whereIn('id', array_keys($workgroupOverrides))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Workgroup $workgroup) => [
                    'id' => $workgroup->id,
                    'name' => $workgroup->name,
                    'enabled' => $workgroupOverrides[$workgroup->id],
                ])
                ->all(),
        ];
    }

    private function logOverride(
        ActivityLogger $activityLogger,
        string $event,
        string $name,
        User|Workgroup $subject,
        ?bool $before,
        ?bool $after
    ): void {
        $activityLogger->custom('feature_flags', $event, $subject, [
            'feature' => $name,
            'subject_type' => $subject instanceof User ? 'user' : 'workgroup',
            'subject_id' => $subject->id,
            'before' => ['enabled' => $before],
            'after' => ['enabled' => $after],
        ]);
    }

    private function featureNames(): \Illuminate\Support\Collection
    {
        return \DB::table('features')
            ->distinct('name')
            ->orderBy('name')
            ->pluck('name');
    }

    private function isAdmin(): bool
    {
        return (bool) Auth::user()?->hasRole('admin');
    }

    private function notScopableResponse(): JsonResponse
    {
        return $this->ValidationErrorResponse([
            'name' => ['This feature flag can only be set globally.'],
        ]);
    }
}
