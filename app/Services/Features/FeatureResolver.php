<?php

namespace App\Services\Features;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class FeatureResolver
{
    private array $overridesByFeature = [];

    public function active(string $feature, ?User $user = null): bool
    {
        return $this->valuesFor([$feature], $user ?? Auth::user())[$feature];
    }

    public function all(?User $user = null): array
    {
        return $this->valuesFor(Feature::defined(), $user ?? Auth::user());
    }

    public function isOverridable(string $feature): bool
    {
        return config("features.flags.{$feature}.overridable", true);
    }

    public function flush(): void
    {
        $this->overridesByFeature = [];
    }

    private function valuesFor(array $features, ?User $user): array
    {
        $this->loadOverrides($features);

        $userScope = Feature::serializeScope($user);
        $subjectScopes = $user
            ? array_map(Feature::serializeScope(...), [$user, ...$user->workgroups->all()])
            : [];

        return collect(Feature::values($features))
            ->map(function (bool $global, string $name) use ($subjectScopes, $userScope) {
                $values = ($this->overridesByFeature[$name] ?? collect())->only($subjectScopes);

                return $values->get($userScope)
                    ?? ($values->isEmpty() ? $global : $values->contains(true));
            })
            ->all();
    }

    private function loadOverrides(array $features): void
    {
        $missing = array_diff(
            array_filter($features, $this->isOverridable(...)),
            array_keys($this->overridesByFeature)
        );

        if ($missing === []) {
            return;
        }

        $rows = DB::table(config('pennant.stores.database.table', 'features'))
            ->whereIn('name', $missing)
            ->where('scope', '!=', Feature::serializeScope(null))
            ->get(['name', 'scope', 'value'])
            ->groupBy('name');

        foreach ($missing as $name) {
            $this->overridesByFeature[$name] = $this->valuesByScope($rows->get($name, collect()));
        }
    }

    private function valuesByScope(Collection $rows): Collection
    {
        return $rows->mapWithKeys(fn ($row) => [$row->scope => (bool) json_decode($row->value)]);
    }
}
