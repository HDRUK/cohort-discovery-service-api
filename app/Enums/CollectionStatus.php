<?php

namespace App\Enums;

enum CollectionStatus: int
{
    case DRAFT = 0;
    case PENDING = 1;
    case ACTIVE = 2;
    case REJECTED = 3;
    case SUSPENDED = 4;

    public static function tryFromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->name, $name) === 0) {
                return $case;
            }
        }

        return null;
    }
}
