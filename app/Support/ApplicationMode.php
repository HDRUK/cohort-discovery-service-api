<?php

namespace App\Support;

class ApplicationMode
{
    public static function isStandalone(): bool
    {
        return config('system.operation_mode') === 'standalone';
    }

    public static function isIntegrated(): bool
    {
        return config('system.operation_mode') === 'integrated';
    }
}
