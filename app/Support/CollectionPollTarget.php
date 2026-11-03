<?php

namespace App\Support;

use App\Enums\TaskType;

final class CollectionPollTarget
{
    public function __construct(
        public readonly string $collectionPid,
        public readonly string $rawTaskType,
    ) {
    }

    public static function fromRouteParameter(string $collectionPidWithTaskType): self
    {
        $parts = explode('.', $collectionPidWithTaskType);

        return new self($parts[0], $parts[1] ?? TaskType::A->value);
    }
}
