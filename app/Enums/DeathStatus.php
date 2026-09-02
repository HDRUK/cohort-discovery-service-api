<?php

namespace App\Enums;

enum DeathStatus: string
{
    case UNKNOWN_OR_ALIVE = "unknown_or_alive";
    case DEATH_RECORDED = "death_recorded";
}
