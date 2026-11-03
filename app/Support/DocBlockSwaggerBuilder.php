<?php

namespace App\Support;

use L5Swagger\CustomBuilderInterface;
use OpenApi\Builder;
use OpenApi\Generator;

/**
 * Keeps our `@OA\...` docblock annotations working. l5-swagger 10+ only reads
 * PHP attributes (`#[OA\...]`) by default, and every controller and model here
 * uses docblocks, so without this the docs come out empty and
 * `l5-swagger:generate` fails with "Required @OA\Info() not found".
 *
 * A plain swagger-php Generator reads both attributes and docblocks (docblocks
 * need doctrine/annotations installed). It's a class name in config rather than
 * an analyser object because `config:cache` can't serialize objects.
 *
 * Can be removed once the annotations are converted to attributes.
 */
class DocBlockSwaggerBuilder implements CustomBuilderInterface
{
    public function create(): Builder
    {
        return (new Builder())
            ->setMode(Builder\Mode::CLASSIC)
            // Use a generator with the default logger (not the builder's collecting
            // one) so annotation warnings still fail the command, as they did before.
            ->withGenerator(fn (Generator $generator) => (new Generator())->setVersion($generator->getVersion()));
    }
}
