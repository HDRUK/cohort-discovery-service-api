<?php

namespace App\Services\QueryContext\Contexts;

use App\Services\QueryContext\QueryContextType;

interface QueryContextInterface
{
    /**
     * Translate the given query context to a specific format.
     *
     * @param mixed $queryContext
     * @return mixed
     */
    public function translate(array $query): array;
    public function getType(): QueryContextType;
}
