<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Claims\MembershipSources;
use App\Traits\Responses;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class AccessPolicyController extends Controller
{
    use Responses;
    use AuthorizesRequests;

    public function index(MembershipSources $membershipSources): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return $this->OKResponse($membershipSources->deployment());
    }
}
