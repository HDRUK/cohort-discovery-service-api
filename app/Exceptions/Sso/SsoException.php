<?php

namespace App\Exceptions\Sso;

use Exception;

/**
 * Base class for SSO failures. The errorCode is safe to expose to the
 * frontend via redirect query params; internal detail stays in the message
 * (logged server-side only).
 */
class SsoException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'sso_failed',
    ) {
        parent::__construct($message);
    }
}
