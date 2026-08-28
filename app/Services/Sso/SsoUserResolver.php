<?php

namespace App\Services\Sso;

use App\Exceptions\Sso\SsoLinkingException;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Resolves an external OIDC identity to a local user.
 *
 * First rule of linking: the (provider, sub) pair is the identity. Email is
 * a hint, not proof - we only link by email when the IdP swears it's
 * verified, otherwise anyone who can register "you@yours.com" at a sloppy
 * IdP inherits your account. Failing both, we create the user on the spot
 * with sensible local defaults.
 */
class SsoUserResolver
{
    public function resolve(OidcProviderConfig $provider, OidcAuthResult $result): User
    {
        return DB::transaction(function () use ($provider, $result) {
            $identity = UserIdentity::where('provider', $provider->slug)
                ->where('provider_sub', $result->sub)
                ->first();

            if ($identity) {
                $identity->update(['last_login_at' => now()]);

                $user = $identity->user;
                if ($result->name && $user->name !== $result->name) {
                    $user->update(['name' => $result->name]);
                }

                return $user;
            }

            if (! $result->email) {
                throw new SsoLinkingException(
                    "Provider [{$provider->slug}] released no email claim; cannot link or provision"
                );
            }

            $existing = User::where('email', $result->email)->first();

            if ($existing) {
                if (! $result->emailVerified) {
                    throw new SsoLinkingException(
                        "Account with this email exists but provider [{$provider->slug}] email is unverified"
                    );
                }

                $this->createIdentity($existing, $provider, $result);

                return $existing;
            }

            $user = User::create([
                'name' => $result->name ?: strstr($result->email, '@', true),
                'email' => $result->email,
                // Empty-password sentinel, same trick as integrated-mode JIT
                // provisioning - these users authenticate at the IdP, not
                // here, and the 'required' rule on login keeps '' unusable
                'password' => '',
            ]);

            $this->createIdentity($user, $provider, $result);
            $this->applyLocalDefaults($user);

            return $user;
        });
    }

    private function createIdentity(User $user, OidcProviderConfig $provider, OidcAuthResult $result): void
    {
        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => $provider->slug,
            'provider_sub' => $result->sub,
            'email_at_link' => $result->email,
            'last_login_at' => now(),
        ]);
    }

    private function applyLocalDefaults(User $user): void
    {
        if (! Feature::active('sso-ensure-defaults-on-jit')) {
            return;
        }

        $defaultWorkgroupId = Workgroup::where('name', 'DEFAULT')->value('id');
        if ($defaultWorkgroupId) {
            $user->workgroups()->syncWithoutDetaching([(int) $defaultWorkgroupId]);
        }

        $user->assignRole('user');
    }
}
