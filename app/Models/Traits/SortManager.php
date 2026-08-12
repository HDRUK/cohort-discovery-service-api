<?php

namespace App\Models\Traits;

/**
 * Applies a `sort=field:direction` request parameter to a query, validated against the
 * model's static $sortableColumns. When no `sort` is supplied it defaults to the first
 * entry of $sortableColumns, descending.
 *
 * SHARED-REPO CANDIDATE: this is a copy of the same trait in HDRUK/gateway-api-2
 * (app/Models/Traits/SortManager.php). It should be extracted into a shared HDR UK
 * Laravel package so both projects depend on a single source. Keep the two copies in
 * sync until then.
 *
 * Note: consuming models must declare `protected static $sortableColumns`. Static
 * analysers (e.g. Intelephense P1014) flag the reference here because a trait cannot
 * declare that property itself — this is expected and benign.
 */
trait SortManager
{
    public function scopeApplySorting($query): mixed
    {
        $input = \request()->all();
        // If no sort option passed, then always default to the first element
        // of our sortableColumns array on the model
        $sort = isset($input['sort']) ? $input['sort'] : static::$sortableColumns[0] . ':desc';

        $tmp = explode(':', $sort);
        $field = strtolower($tmp[0]);

        if (isset(static::$sortableColumns) && !in_array(strtolower($field), static::$sortableColumns)) {
            throw new \InvalidArgumentException('field ' . $field . ' is not sortable.');
        }

        $direction = strtolower($tmp[1] ?? '');
        if (!in_array($direction, ['asc', 'desc'])) {
            throw new \InvalidArgumentException('invalid sort direction ' . $direction);
        }

        return $query->orderBy($field, $direction);
    }
}
