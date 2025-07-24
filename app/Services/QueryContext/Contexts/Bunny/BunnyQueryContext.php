<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\QueryContextType;
use App\Services\QueryContext\Contexts\QueryContextInterface;

class BunnyQueryContext implements QueryContextInterface
{
    public function translate(string $jsonQuery): mixed
    {
        // Implementation for translating a query in the context of Bunny
        // This is a placeholder to demonstrate the calling of the translate method.
        return json_decode($jsonQuery, true);
    }

    public function getType(): QueryContextType
    {
        return QueryContextType::Bunny;
    }
}
