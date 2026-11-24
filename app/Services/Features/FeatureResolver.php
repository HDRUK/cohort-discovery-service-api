<?php

namespace App\Services\Features;

use App\Models\User;
use App\Models\Workgroup;
use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class FeatureResolver
{
    private array $rowsByFeature = [];

    public function active(string $feature, ?User $user = null): bool
    {
        $user = $this->isGlobalOnly($feature) ? null : ($user ?? Auth::user());

        return $this->valueOf($this->rowsOf($feature), $this->scopesFor($user), $user);
    }

    public function allFor(?User $user = null): array
    {
        $user ??= Auth::user();
        $scopes = $this->scopesFor($user);
        $globalScopes = $this->scopesFor(null);

        return DB::table('features')
            ->whereIn('scope', $scopes)
            ->get(['name', 'scope', 'value'])
            ->groupBy('name')
            ->map(fn (Collection $rows, string $name) => $this->isGlobalOnly($name)
                ? $this->valueOf($rows, $globalScopes, null)
                : $this->valueOf($rows, $scopes, $user))
            ->all();
    }

    public function flush(): void
    {
        $this->rowsByFeature = [];
    }

    public function overrides(?string $feature = null): array
    {
        $globalScope = Feature::serializeScope(null);

        return DB::table('features')
            ->when($feature, fn ($query) => $query->where('name', $feature))
            ->orderBy('name')
            ->get(['name', 'scope', 'value'])
            ->groupBy('name')
            ->map(fn (Collection $rows, string $name) => [
                'global' => $this->decode($rows->where('scope', $globalScope)->pluck('value')->first() ?? 'false'),
                'overridable' => ! $this->isGlobalOnly($name),
                'users' => $this->subjectValues($rows, User::class),
                'workgroups' => $this->subjectValues($rows, Workgroup::class),
            ])
            ->all();
    }

    public function isGlobalOnly(string $feature): bool
    {
        return ! config("features.flags.{$feature}.overridable", true);
    }

    private function subjectValues(Collection $rows, string $modelClass): array
    {
        $scopePrefix = $modelClass.'|';

        return $rows
            ->filter(fn ($row) => str_starts_with($row->scope, $scopePrefix))
            ->mapWithKeys(fn ($row) => [
                (int) Str::after($row->scope, $scopePrefix) => $this->decode($row->value),
            ])
            ->all();
    }

    private function rowsOf(string $feature): Collection
    {
        return $this->rowsByFeature[$feature] ??= DB::table('features')
            ->where('name', $feature)
            ->get(['name', 'scope', 'value']);
    }

    private function scopesFor(?User $user): array
    {
        if (! $user) {
            return [Feature::serializeScope(null)];
        }

        return [
            Feature::serializeScope(null),
            Feature::serializeScope($user),
            ...$this->workgroupsOf($user)->map(fn (Workgroup $workgroup) => Feature::serializeScope($workgroup)),
        ];
    }

    private function workgroupsOf(User $user): Collection
    {
        return $user->relationLoaded('workgroups')
            ? $user->workgroups
            : $user->workgroups()->get(['workgroups.id']);
    }

    private function valueOf(Collection $rows, array $scopes, ?User $user): bool
    {
        $globalScope = Feature::serializeScope(null);
        $userScope = $user ? Feature::serializeScope($user) : null;

        $values = $rows
            ->whereIn('scope', $scopes)
            ->mapWithKeys(fn ($row) => [$row->scope => $this->decode($row->value)]);

        if ($userScope && $values->has($userScope)) {
            return $values->get($userScope);
        }

        $workgroupValues = $values->except(array_filter([$globalScope, $userScope]));

        return $workgroupValues->isEmpty()
            ? $values->get($globalScope, false)
            : $workgroupValues->contains(true);
    }

    private function decode(string $value): bool
    {
        return (bool) json_decode($value);
    }
}
