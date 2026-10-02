<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Authentication\LoginMethods;
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

        if (config('sso.enabled')) {
            $providers = config('sso.providers', []);
            foreach ($providers as $slug => $config) {
                $methods[] = [
                    'type' => 'oidc',
                    'slug' => $slug,
                    'label' => $config['label'] ?? 'Single Sign-On',
                    'redirect_url' => url("/api/auth/sso/{$slug}/redirect"),
                ];
            }
        }

        return $this->OKResponse($methods);
    }
}
