<?php

namespace App\Services\QueryContext\Contexts;

use App\Services\QueryContext\QueryContextType;

interface QueryContextInterface
{
    /**
     * Translate the given query context to a specific format.
     */
    public function translate(array $query, bool $flattenNestedGroups = true, bool $useDeathObservation = false, bool $supportsLocation = false): array;

    /**
     * Return the type of this QueryContentInterface.
     */
    public function getType(): QueryContextType;
}
