<?php

namespace App\Services\QueryContext\Contexts\Beacon;

use App\Services\QueryContext\QueryContextType;
use App\Services\QueryContext\Contexts\QueryContextInterface;

class BeaconQueryContext implements QueryContextInterface
{
    public function translate(array $query): array
    {
        return $query;
    }

    public function getType(): QueryContextType
    {
        return QueryContextType::Beacon;
    }
}
