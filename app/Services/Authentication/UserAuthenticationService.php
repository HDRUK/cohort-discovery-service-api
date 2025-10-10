<?php

namespace App\Services\Authentication;

use App\Support\ApplicationMode;

class UserAuthenticationService
{
    public static function make()
    {
        if (ApplicationMode::isStandalone()) {
            return new StandaloneAuthenticationService();
        }

        return new IntegratedAuthenticationService();
    }
}
