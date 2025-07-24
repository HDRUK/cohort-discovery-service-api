<?php

namespace App\Services\QueryContext;

use App\Services\QueryContext\Translators\{
    QueryTranslatorInterface
};
use App\Exceptions\Errors_1xxx\UnsupportedContextTypeException;

class QueryContextManager
{
    protected array $contexts = [];

    public function __construct()
    {
        $tagged = $container->tagged('query_contexts');

        foreach ($tagged as $context) {
            if ($context instanceof QueryTranslatorInterface) {
                $this->contexts[$context->getType()->value] = $context;
            }
        }
    }

    public function handle(string $jsonQuery, ContextType $contextType): mixed
    {
        $context = $this->contexts[$contextType->value] ?? null;
        if (!$context) {
            throw new UnsupportedContextTypeException($contextType->value);
        }

        return $context->translate($jsonQuery);
    }
}
