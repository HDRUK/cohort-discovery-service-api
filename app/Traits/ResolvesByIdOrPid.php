<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait ResolvesByIdOrPid
{
    /**
     * Constrain the query to the row identified by a numeric primary key or a
     * UUID pid - the "id or pid" lookup used across the API's controllers.
     */
    public function scopeWhereIdOrPid(Builder $query, int|string $key): Builder
    {
        return $query->when(
            ctype_digit((string) $key),
            fn ($q) => $q->where($this->getKeyName(), $key),
            fn ($q) => $q->where('pid', $key),
        );
    }
}
