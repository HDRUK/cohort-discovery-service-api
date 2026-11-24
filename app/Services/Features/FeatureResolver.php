<?php

namespace App\Services\Features;

use App\Models\User;
use App\Models\Workgroup;
use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class FeatureResolver
{
    public function active(string $feature, ?User $user): bool
    {
        $user = $this->isGlobalOnly($feature) ? null : $user;

        return $this->resolve($this->rowsFor($user, $feature), $user)[$feature] ?? false;
    }

    public function allFor(?User $user): array
    {
        return $this->resolve($this->rowsFor($user), $user);
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
                'global' => $this->decode($rows->firstWhere('scope', $globalScope)?->value ?? 'false'),
                'overridable' => ! $this->isGlobalOnly($name),
                'users' => $this->subjectValues($rows, User::class),
                'workgroups' => $this->subjectValues($rows, Workgroup::class),
            ])
            ->all();
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

    private function rowsFor(?User $user, ?string $feature = null): Collection
    {
        return DB::table('features')
            ->when($feature, fn ($query) => $query->where('name', $feature))
            ->whereIn('scope', $this->scopesFor($user))
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

    private function resolve(Collection $rows, ?User $user): array
    {
        $globalScope = Feature::serializeScope(null);
        $userScope = $user ? Feature::serializeScope($user) : null;

        return $rows
            ->groupBy('name')
            ->map(function (Collection $rows, string $name) use ($globalScope, $userScope) {
                $values = $rows->mapWithKeys(fn ($row) => [$row->scope => $this->decode($row->value)]);

                if ($this->isGlobalOnly($name)) {
                    return $values->get($globalScope, false);
                }

                if ($userScope && $values->has($userScope)) {
                    return $values->get($userScope);
                }

                $workgroupValues = $values->except(array_filter([$globalScope, $userScope]));

                return $workgroupValues->isEmpty()
                    ? $values->get($globalScope, false)
                    : $workgroupValues->contains(true);
            })
            ->all();
    }

    public function isGlobalOnly(string $feature): bool
    {
        return in_array($feature, config('features.global_only'), true);
    }

    private function decode(string $value): bool
    {
        return (bool) json_decode($value);
    }
}
