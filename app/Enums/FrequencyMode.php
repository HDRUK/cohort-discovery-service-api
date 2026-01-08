<?php

namespace App\Enums;

enum FrequencyMode: string
{
    case WEEKLY = '1';
    case MONTHLY = '2';
    case QUARTERLY = '3';
    case BIANNUALLY = '4';
}
