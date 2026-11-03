<?php

namespace App\Services\Submitters;

use App\Models\Query;
use App\Models\User;
use Laravel\Pennant\Feature;

class QueryRateLimiter
{
    public function hasReachedMonthlyLimit(User $user): bool
    {
        if (! Feature::active('query-rate-limit')) {
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
        $unlimited = ! Feature::active('query-rate-limit') || $this->isExempt($user);

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
