<?php

namespace App\Services\Authentication;

class LoginMethods
{
    public function passwordEnabled(): bool
    {
        return config('auth.password_login_enabled', true);
    }
}
