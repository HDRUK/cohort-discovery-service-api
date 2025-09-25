<?php

namespace App\Services\QueryContext\Contexts;

use App\Services\QueryContext\QueryContextType;

interface QueryContextInterface
{
    /**
     * Translate the given query context to a specific format.
     *
     * @param array $query
     * @return array
     */
    public function translate(array $query): array;

    /**
     * Return the type of this QueryContentInterface.
     *
     * @return QueryContextType
     */
    public function getType(): QueryContextType;
}
