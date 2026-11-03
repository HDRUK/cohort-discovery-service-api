<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Authentication\LoginMethods;
use App\Services\Sso\OidcProviderConfig;
use App\Traits\Responses;

class AuthMethodsController extends Controller
{
    use Responses;

    public function __construct(protected LoginMethods $loginMethods)
    {
    }

    public function index()
    {
        $methods = [];

        if ($this->loginMethods->passwordEnabled()) {
            $methods[] = [
                'type' => 'password',
                'label' => 'Email and password',
            ];
        }

        if ($this->loginMethods->ssoAvailable()) {
            foreach (OidcProviderConfig::enabledProviders() as $slug => $label) {
                $methods[] = [
                    'type' => 'oidc',
                    'slug' => $slug,
                    'label' => $label,
                    'redirect_url' => url("/api/auth/sso/{$slug}/redirect"),
                ];
            }
        }

        return $this->OKResponse($methods);
    }
}
