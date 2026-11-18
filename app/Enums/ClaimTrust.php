<?php

namespace App\Enums;

enum ClaimTrust: string
{
    case NEVER = 'never';
    case FIRST_LOGIN = 'first_login';
    case ALWAYS = 'always';
}
