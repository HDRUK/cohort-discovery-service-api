<?php

namespace App\Enums;

enum CollectionStatus: int
{
    case INACTIVE = 0;
    case ACTIVE = 1;
    case SUSPENDED = 2;

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
