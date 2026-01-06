<?php

namespace App\SwaggerProcessors;

use OpenApi\Analysis;

class IgnorePluginsProcessor
{
    public function __invoke(Analysis $analysis)
    {
        foreach ($analysis->annotations as $key => $annotation) {
            // Only process OpenAPI annotations or any class with _context
            if (isset($annotation->_context)) {
                $fqcn = $annotation->_context->fullyQualifiedName ?? null;

                // Skip annotations in the App\Plugins namespace
                if ($fqcn && str_starts_with($fqcn, 'App\\Plugins\\')) {
                    unset($analysis->annotations[$key]);
                }
            }
        }
    }
}
