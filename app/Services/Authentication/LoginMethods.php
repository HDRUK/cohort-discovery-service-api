<?php

namespace App\Services\Authentication;

use App\Services\Sso\OidcProviderConfig;
use App\Support\ApplicationMode;

class LoginMethods
{
    public function passwordEnabled(): bool
    {
        return config('auth.password_login.enabled', true) || ! $this->ssoAvailable();
    }

    public function ssoAvailable(): bool
    {
        return ApplicationMode::isStandalone()
            && (bool) config('sso.enabled')
            && (bool) config('sso.frontend_callback_url')
            && OidcProviderConfig::enabledProviders() !== [];
    }
}
