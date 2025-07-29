<?php

namespace App\Services\QueryContext;

use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Exceptions\Errors_1xxx\UnsupportedContextTypeException;
use Illuminate\Container\Container;

class QueryContextManager
{
    protected array $contexts = [];

    public function __construct(Container $container)
    {
        $tagged = $container->tagged('query_contexts');

        foreach ($tagged as $context) {
            if ($context instanceof QueryContextInterface) {
                $this->contexts[$context->getType()->value] = $context;
            }
        }
    }

    public function handle(array $query, QueryContextType $contextType): array
    {
        $context = $this->contexts[$contextType->value] ?? null;
        if (!$context) {
            throw new UnsupportedContextTypeException($contextType->value);
        }

        return $context->translate($query);
    }
}
