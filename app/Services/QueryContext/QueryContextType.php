<?php

namespace App\Services\QueryContext;

enum QueryContextType: string
{
    case Bunny = 'bunny';
    case Beacon = 'beacon';
}
