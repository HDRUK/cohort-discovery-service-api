<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Claims\MembershipSources;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;

class AccessPolicyController extends Controller
{
    use Responses;

    public function index(MembershipSources $membershipSources): JsonResponse
    {
        return $this->OKResponse($membershipSources->deployment());
    }
}
