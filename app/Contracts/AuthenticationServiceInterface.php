<?php

namespace App\Contracts;

use Illuminate\Http\Request;

interface AuthenticationServiceInterface
{
    public function authenticate(Request $request): mixed;

    public function getRedirectUrlFromToken(string $tokenString): ?string;
}
