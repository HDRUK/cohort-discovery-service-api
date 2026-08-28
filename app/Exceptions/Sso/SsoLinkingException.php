<?php

namespace App\Exceptions\Sso;

class SsoLinkingException extends SsoException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'account_linking_failed');
    }
}
