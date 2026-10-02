<?php

namespace App\Services\Sso;

use Illuminate\Support\Facades\Cache;

/**
 * Parks the IdP's id_token so logout can hand it back as id_token_hint.
 *
 * Without the hint an IdP cannot tell which session to end: Keycloak (and
 * anything else following RP-initiated logout) stops to ask the user whether
 * they meant it, which reads as a broken logout. The id_token itself must not
 * go to the browser - that is the whole point of brokering OIDC here - so the
 * app's own JWT carries an opaque ticket instead, and logout trades it back.
 *
 * Tickets outlive the app token deliberately: a user who logs out at the very
 * end of a session should still get a clean logout.
 */
class LogoutTicketStore
{
    private const CACHE_PREFIX = 'sso:logout:';

    public function issue(string $idToken): string
    {
        $ticket = bin2hex(random_bytes(32));

        Cache::put(self::CACHE_PREFIX.$ticket, $idToken, $this->ttlSeconds());

        return $ticket;
    }

    public function redeem(string $ticket): ?string
    {
        return Cache::pull(self::CACHE_PREFIX.$ticket);
    }

    private function ttlSeconds(): int
    {
        return ((int) config('system.standalone_jwt_ttl_minutes', 60) * 60) + 300;
    }
}
