<?php

namespace App\Exceptions\Sso;

class ProviderNotConfiguredException extends SsoException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'provider_not_configured');
    }
}
