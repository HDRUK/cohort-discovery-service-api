<?php

namespace App\Support;

use Psr\Clock\ClockInterface;

/**
 * Minimal PSR-20 clock for token validation. Exists because lcobucci/clock
 * fell out of our dependency tree when league/oauth2-server 9.4 stopped
 * requiring it - and one interface method is not worth a composer package.
 */
class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
