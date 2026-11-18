<?php

namespace App\Enums;

enum MembershipMode: string
{
    case INTERNAL = 'internal';
    case FIRST_LOGIN = 'first_login';
    case ADDITIVE = 'additive';
    case AUTHORITATIVE = 'authoritative';

    public function isEditable(): bool
    {
        return $this !== self::AUTHORITATIVE;
    }
}
