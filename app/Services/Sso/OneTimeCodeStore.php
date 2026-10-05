<?php

namespace App\Services\Sso;

use Illuminate\Support\Facades\Cache;

/**
 * Short lived, single-use handoff codes for getting the freshly minted token
 * to the FE. The callback parks the token here, sends the browser off with
 * just the code, and the FE trades it in via /exchange. Tokens in URLs end
 * up in browser history and access logs - codes that die in 60 seconds
 * (and on first use) do not.
 */
class OneTimeCodeStore
{
    private const CACHE_PREFIX = 'sso:handoff:';

    public function issue(string $accessToken): string
    {
        $code = bin2hex(random_bytes(32));

        Cache::put(
            self::CACHE_PREFIX.$code,
            $accessToken,
            config('sso.handoff_code_ttl_seconds', 60)
        );

        return $code;
    }

    public function redeem(string $code): ?string
    {
        return Cache::pull(self::CACHE_PREFIX.$code);
    }
}
