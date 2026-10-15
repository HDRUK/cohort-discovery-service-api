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

        if ($user->hasRole('admin') || $user->custodians()->exists()) {
            return false;
        }

        return Query::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count() >= config('api.monthly_query_limit');
    }
}
