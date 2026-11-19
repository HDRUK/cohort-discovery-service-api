<?php

namespace App\Providers;

use App\Enums\ClaimTrust;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\Claims\MembershipSources;
use Illuminate\Support\ServiceProvider;

class ClaimsAccessControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->assertTrustIsValid();

        $this->app->scoped(MembershipSources::class);
    }

    private function assertTrustIsValid(): void
    {
        foreach (ClaimSyncPolicy::SUBJECTS as $subject) {
            $trust = config("claimsaccesscontrol.sync.{$subject}.trust");

            if (is_string($trust) && ClaimTrust::tryFrom($trust) !== null) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'CLAIM_SYNC_%s is [%s]; expected one of: %s (claimsaccesscontrol.sync.%s.trust).',
                strtoupper($subject),
                is_scalar($trust) ? (string) $trust : gettype($trust),
                implode(', ', array_column(ClaimTrust::cases(), 'value')),
                $subject
            ));
        }
    }
}
