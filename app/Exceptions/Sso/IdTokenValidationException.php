<?php

namespace App\Exceptions\Sso;

class IdTokenValidationException extends SsoException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'invalid_id_token');
    }
}
