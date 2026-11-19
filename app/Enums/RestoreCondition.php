<?php

namespace App\Enums;

enum RestoreCondition: string
{
    case EVERY_SYNC = 'every_sync';
    case SDE_APPROVED = 'sde_approved';
}
