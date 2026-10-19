<?php

namespace App\Exceptions\Sso;

class InvalidStateException extends SsoException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'invalid_state');
    }
}
