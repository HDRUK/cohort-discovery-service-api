<?php

namespace App\Services\Features;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class FeatureResolver
{
    public function active(string $feature, ?User $user): bool
    {
        return $this->valuesFor([$feature], $user)[$feature];
    }

    public function all(?User $user): array
    {
        return $this->valuesFor(Feature::defined(), $user);
    }

    public function isOverridable(string $feature): bool
    {
        return config("features.flags.{$feature}.overridable", true);
    }

    private function valuesFor(array $features, ?User $user): array
    {
        $userScope = Feature::serializeScope($user);
        $overrides = $this->overrideValues(array_filter($features, $this->isOverridable(...)), $user);

        return collect(Feature::values($features))
            ->map(function (bool $global, string $name) use ($overrides, $userScope) {
                $values = $overrides->get($name, collect());

                return $values->get($userScope)
                    ?? ($values->isEmpty() ? $global : $values->contains(true));
            })
            ->all();
    }

    private function overrideValues(array $features, ?User $user)
    {
        if (! $user || $features === []) {
            return collect();
        }

        $scopes = array_map(
            Feature::serializeScope(...),
            [$user, ...$user->workgroups->all()]
        );

        return DB::table(config('pennant.stores.database.table', 'features'))
            ->whereIn('name', $features)
            ->whereIn('scope', $scopes)
            ->get(['name', 'scope', 'value'])
            ->groupBy('name')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [$row->scope => (bool) json_decode($row->value)]));
    }
}
