<?php

namespace App\Services\Collections;

use Illuminate\Support\Arr;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

class CollectionSchema
{
    private const MAX_ERRORS = 50;

    private string $id;

    private array $definition;

    private ?array $document = null;

    public function __construct()
    {
        $this->id = config('collection_schema.active');
        $this->definition = config('collection_schema.schemas')[$this->id]
            ?? throw new \RuntimeException("Unknown collection schema {$this->id}");
    }

    public function id(): string
    {
        return $this->id;
    }

    public function document(): array
    {
        return $this->document ??= json_decode(
            file_get_contents($this->definition['path']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    public function errors(array $metadata): array
    {
        $validator = new Validator(null, self::MAX_ERRORS, false);
        $result = $validator->validate(Helper::toJSON($metadata), Helper::toJSON($this->document()));

        if ($result->isValid()) {
            return [];
        }

        return (new ErrorFormatter())->format(
            $result->error(),
            true,
            null,
            fn (ValidationError $error) => implode('.', $error->data()->fullPath())
        );
    }

    public function columnsFrom(array $metadata): array
    {
        return array_map(
            fn (string $path) => Arr::get($metadata, $path),
            $this->definition['columns']
        );
    }

    public function withColumns(array $metadata, array $columns): array
    {
        foreach (Arr::only($this->definition['columns'], array_keys($columns)) as $column => $path) {
            if ($columns[$column] === null || $columns[$column] === '') {
                Arr::forget($metadata, $path);
            } else {
                Arr::set($metadata, $path, $columns[$column]);
            }
        }

        return $metadata;
    }
}
