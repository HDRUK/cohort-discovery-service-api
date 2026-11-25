<?php

namespace App\Services\Features;

use App\Jobs\RefreshLatestDistributionsView;
use App\Models\User;
use App\Models\Workgroup;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class FeatureAdminService
{
    private const CENTRAL_DOMAIN_FEATURE = 'distribution-use-central-domain';

    public function __construct(
        private FeatureResolver $resolver,
        private ActivityLogger $activityLogger
    ) {
    }

    public function exists(string $feature): bool
    {
        return in_array($feature, Feature::defined(), true);
    }

    public function overrides(?string $feature = null): array
    {
        $names = collect($feature ? [$feature] : Feature::defined())->sort()->values();
        $globals = Feature::values($names->all());

        $rows = DB::table($this->table())
            ->whereIn('name', $names)
            ->where('scope', '!=', Feature::serializeScope(null))
            ->get(['name', 'scope', 'value'])
            ->groupBy('name');

        return $names
            ->mapWithKeys(fn (string $name) => [$name => [
                'global' => $globals[$name],
                'overridable' => $this->resolver->isOverridable($name),
                'users' => $this->subjectValues($rows->get($name, collect()), User::class),
                'workgroups' => $this->subjectValues($rows->get($name, collect()), Workgroup::class),
            ]])
            ->all();
    }

    public function setGlobal(string $name, bool $enabled): void
    {
        Feature::active($name);

        DB::transaction(function () use ($name, $enabled) {
            $before = $this->lockedGlobalValue($name);

            $enabled ? Feature::for(null)->activate($name) : Feature::for(null)->deactivate($name);

            $this->activityLogger->custom('feature_flags', 'updated', null, [
                'feature' => $name,
                'before' => ['enabled' => $before],
                'after' => ['enabled' => $enabled],
            ]);

            if ($name === self::CENTRAL_DOMAIN_FEATURE && $before !== $enabled) {
                RefreshLatestDistributionsView::dispatch()->afterCommit();
            }
        });
    }

    public function replaceOverrides(string $name, array $users, array $workgroups): void
    {
        Feature::active($name);

        DB::transaction(function () use ($name, $users, $workgroups) {
            $this->lockedGlobalValue($name);
            $current = $this->overrides($name)[$name];

            $this->syncOverrides($name, User::class, $current['users'], $users);
            $this->syncOverrides($name, Workgroup::class, $current['workgroups'], $workgroups);
        });
    }

    private function lockedGlobalValue(string $name): bool
    {
        $row = DB::table($this->table())
            ->where('name', $name)
            ->where('scope', Feature::serializeScope(null))
            ->lockForUpdate()
            ->first();

        return (bool) json_decode($row->value);
    }

    private function subjectValues(Collection $rows, string $modelClass): array
    {
        $scopePrefix = $modelClass.'|';

        return $rows
            ->filter(fn ($row) => str_starts_with($row->scope, $scopePrefix))
            ->mapWithKeys(fn ($row) => [(int) Str::after($row->scope, $scopePrefix) => (bool) json_decode($row->value)])
            ->all();
    }

    private function syncOverrides(string $name, string $modelClass, array $current, array $wanted): void
    {
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

            $this->logOverride($after === null ? 'override_cleared' : 'override_set', $name, $subject, $before, $after);
        }
    }

    private function logOverride(string $event, string $name, User|Workgroup $subject, ?bool $before, ?bool $after): void
    {
        $this->activityLogger->custom('feature_flags', $event, $subject, [
            'feature' => $name,
            'subject_type' => $subject instanceof User ? 'user' : 'workgroup',
            'subject_id' => $subject->id,
            'before' => ['enabled' => $before],
            'after' => ['enabled' => $after],
        ]);
    }

    private function table(): string
    {
        return config('pennant.stores.database.table', 'features');
    }
}
