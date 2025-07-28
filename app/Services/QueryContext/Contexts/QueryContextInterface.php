<?php

namespace App\Services\QueryContext\Contexts;

use App\Services\QueryContext\QueryContextType;

interface QueryContextInterface
{
    /**
     * Translate the given query context to a specific format.
     *
     * @param string $jsonQuery
     * @return mixed
     */
    public function translate(string $jsonQuery): mixed;
    
    /**
     * Return the type of this QueryContentInterface.
     * 
     * @return QueryContextType
     */
    public function getType(): QueryContextType;
}
