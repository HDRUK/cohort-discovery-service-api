<?php

namespace App\Support;

/**
 * Which mode this deployment runs in.
 *
 * STANDALONE is the mode for everyone. The API is its own identity authority:
 * local accounts, optionally fronted by an external OIDC provider.
 *
 * INTEGRATED exists for one purpose - sharing tokens with the HDR UK Gateway,
 * which acts as the identity provider and the source of roles, workgroups and
 * custodian membership. It is HDR UK infrastructure and is not a general
 * purpose SSO mechanism; connecting a third-party IdP to it will not work,
 * because the trust model assumes the Gateway on the other end.
 */
class ApplicationMode
{
    public const STANDALONE = 'standalone';

    public const INTEGRATED = 'integrated';

    public const MODES = [self::STANDALONE, self::INTEGRATED];

    public static function isStandalone(): bool
    {
        return config('system.operation_mode') === self::STANDALONE;
    }

    public static function isIntegrated(): bool
    {
        return config('system.operation_mode') === self::INTEGRATED;
    }

    public static function current(): string
    {
        return (string) config('system.operation_mode');
    }
}
