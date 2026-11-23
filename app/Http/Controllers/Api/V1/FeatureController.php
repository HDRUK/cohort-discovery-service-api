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
use Illuminate\Support\Facades\DB;
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

        $overrides = collect($this->resolver->overrides());

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
    }

    public function replaceOverrides(Request $request, string $name, ActivityLogger $activityLogger): JsonResponse
    {
        if (! $this->isAdmin()) {
            return $this->ForbiddenResponse();
        }

        if (! $this->featureNames()->contains($name)) {
            return $this->NotFoundResponse();
        }

        if ($this->resolver->isGlobalOnly($name)) {
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

        $current = $this->resolver->overrides($name)[$name];

        DB::transaction(function () use ($name, $current, $validated, $activityLogger) {
            $this->syncOverrides($name, User::class, $current['users'], $validated['users'], $activityLogger);
            $this->syncOverrides($name, Workgroup::class, $current['workgroups'], $validated['workgroups'], $activityLogger);
        });

        return $this->OKResponse([]);
    }

    private function syncOverrides(
        string $name,
        string $modelClass,
        array $current,
        array $wanted,
        ActivityLogger $activityLogger
    ): void {
        $wanted = collect($wanted)->mapWithKeys(fn (array $override) => [
            (int) $override['id'] => (bool) $override['enabled'],
        ]);

        $subjects = $modelClass::whereIn('id', $wanted->keys()->merge(array_keys($current))->unique())->get();

        foreach ($subjects as $subject) {
            $before = $current[$subject->id] ?? null;
            $after = $wanted->get($subject->id);

            if ($before === $after) {
                continue;
            }

            match ($after) {
                true => Feature::for($subject)->activate($name),
                false => Feature::for($subject)->deactivate($name),
                null => Feature::for($subject)->forget($name),
            };

            $this->logOverride(
                $activityLogger,
                $after === null ? 'override_cleared' : 'override_set',
                $name,
                $subject,
                $before,
                $after
            );
        }
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
        return DB::table('features')
            ->distinct('name')
            ->orderBy('name')
            ->pluck('name');
    }

    private function isAdmin(): bool
    {
        return (bool) Auth::user()?->hasRole('admin');
    }
}
