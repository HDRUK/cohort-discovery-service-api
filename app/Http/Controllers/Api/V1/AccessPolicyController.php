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

    /**
     * @OA\Get(
     *     path="/api/v1/access_policy",
     *     summary="Which memberships an identity provider or the Gateway manages on this deployment (admin only)",
     *     tags={"Users"},
     *     @OA\Response(
     *         response=200,
     *         description="Deployment-wide membership policy, per subject (workgroups, roles, custodians)",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="success"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="operation_mode", type="string", enum={"standalone", "integrated"}, example="standalone"),
     *                 @OA\Property(
     *                     property="providers",
     *                     type="object",
     *                     description="Claim sources, slug => label. Integrated mode reports gateway.",
     *                     example={"keycloak": "Keycloak"}
     *                 ),
     *                 @OA\Property(property="workgroups", ref="#/components/schemas/AccessPolicySubject"),
     *                 @OA\Property(property="roles", ref="#/components/schemas/AccessPolicySubject"),
     *                 @OA\Property(property="custodians", ref="#/components/schemas/AccessPolicySubject")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     *
     * @OA\Schema(
     *     schema="AccessPolicySubject",
     *     type="object",
     *     @OA\Property(property="mode", type="string", enum={"internal", "first_login", "additive", "authoritative"}, example="authoritative"),
     *     @OA\Property(property="authoritative", type="boolean", description="Only ever true for a subject that syncs", example=true),
     *     @OA\Property(property="editable", type="boolean", example=false),
     *     @OA\Property(
     *         property="restored_on_sync",
     *         type="array",
     *         description="Entries a sync re-applies whatever the claims say",
     *         @OA\Items(
     *             type="object",
     *             @OA\Property(property="id", type="integer", example=2),
     *             @OA\Property(property="name", type="string", example="DEFAULT"),
     *             @OA\Property(property="when", type="string", enum={"every_sync", "sde_approved"}, example="every_sync")
     *         )
     *     )
     * )
     */
    public function index(MembershipSources $membershipSources): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return $this->OKResponse($membershipSources->deployment());
    }
}
