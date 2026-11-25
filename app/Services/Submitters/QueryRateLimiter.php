<?php

namespace App\Services\Submitters;

use App\Models\Query;
use App\Models\User;
use App\Services\Features\FeatureResolver;

class QueryRateLimiter
{
    public function __construct(private FeatureResolver $featureResolver)
    {
    }

    public function hasReachedMonthlyLimit(User $user): bool
    {
        if (! $this->featureResolver->active('query-rate-limit', $user)) {
            return false;
        }

        if ($this->isExempt($user)) {
            return false;
        }

        return $this->usedThisMonth($user) >= config('api.monthly_query_limit');
    }

    public function usageSummary(User $user): array
    {
        $limit = config('api.monthly_query_limit');
        $used = $this->usedThisMonth($user);
        $unlimited = ! $this->featureResolver->active('query-rate-limit', $user) || $this->isExempt($user);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $unlimited ? null : max(0, $limit - $used),
            'unlimited' => $unlimited,
        ];
    }

    private function isExempt(User $user): bool
    {
        return $user->hasRole('admin') || $user->custodians()->exists();
    }

    private function usedThisMonth(User $user): int
    {
        return Query::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
