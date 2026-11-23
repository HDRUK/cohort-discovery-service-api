<?php

namespace App\Services\Features;

use App\Models\User;
use App\Models\Workgroup;
use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class FeatureResolver
{
    public function isScopable(string $feature): bool
    {
        return in_array($feature, config('features.scopable', []), true);
    }

    public function active(string $feature, ?User $user): bool
    {
        return $this->resolve($this->rowsFor($user, $feature), $user)[$feature] ?? false;
    }

    /**
     * @return array<string, bool>
     */
    public function allFor(?User $user): array
    {
        return $this->resolve($this->rowsFor($user), $user);
    }

    public function overrideFor(string $feature, Model $subject): ?bool
    {
        $value = DB::table('features')
            ->where('name', $feature)
            ->where('scope', Feature::serializeScope($subject))
            ->value('value');

        return $value === null ? null : $this->decode($value);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<int, bool>
     */
    public function overridesOf(string $feature, string $modelClass): array
    {
        $scopePrefix = $modelClass.'|';

        return DB::table('features')
            ->where('name', $feature)
            ->pluck('value', 'scope')
            ->filter(fn (string $value, string $scope) => str_starts_with($scope, $scopePrefix))
            ->mapWithKeys(fn (string $value, string $scope) => [
                (int) Str::after($scope, $scopePrefix) => $this->decode($value),
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

    /**
     * @return array<int, string>
     */
    private function scopesFor(?User $user): array
    {
        if (! $user) {
            return [Feature::serializeScope(null)];
        }

        return [
            Feature::serializeScope(null),
            Feature::serializeScope($user),
            ...$user->workgroups()
                ->get(['workgroups.id'])
                ->map(fn (Workgroup $workgroup) => Feature::serializeScope($workgroup)),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function resolve(Collection $rows, ?User $user): array
    {
        $globalScope = Feature::serializeScope(null);
        $userScope = $user ? Feature::serializeScope($user) : null;

        return $rows
            ->groupBy('name')
            ->map(function (Collection $rows, string $name) use ($globalScope, $userScope) {
                $values = $rows->mapWithKeys(fn ($row) => [$row->scope => $this->decode($row->value)]);
                $global = $values->get($globalScope, false);

                if (! $this->isScopable($name)) {
                    return $global;
                }

                if ($userScope && $values->has($userScope)) {
                    return $values->get($userScope);
                }

                $workgroupValues = $values->except(array_filter([$globalScope, $userScope]));

                return $workgroupValues->isEmpty() ? $global : $workgroupValues->contains(true);
            })
            ->all();
    }

    private function decode(string $value): bool
    {
        return (bool) json_decode($value);
    }
}
