<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\UserHasWorkgroup;
use App\Http\Controllers\Controller;
use App\Traits\Responses;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    use Responses;

    public function index(Request $request): JsonResponse
    {
        // Stub
        return $this->OKResponse([]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        // Stub
        return $this->OKResponse([]);
    }

    public function store(Request $request): JsonResponse
    {
        // Stub
        return $this->OKResponse([]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        // Stub
        return $this->OKResponse([]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // Stub
        return $this->OKResponse([]);
    }

    public function getMe(Request $request)
    {
        $user = User::with('workgroups')->findOrFail(Auth::id());
        return $this->OKResponse($user);
    }

    public function addToWorkgroup(Request $request, int $id): JsonResponse
    {
        $input = $request->validate([
            'workgroup_id' => 'required|exists:workgroups,id',
        ]);

        try {
            $user = User::findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        try {
            $workgroup = Workgroup::findOrFail($input['workgroup_id']);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        $userHasWorkgroup = UserHasWorkgroup::firstOrCreate([
            'user_id' => $user->id,
            'workgroup_id' => $input['workgroup_id'],
        ]);

        return $this->OKResponse([$userHasWorkgroup]);
    }

    public function removeFromWorkgroup(Request $request, int $id): JsonResponse
    {
        $input = $request->validate([
            'workgroup_id' => 'required|exists:workgroups,id',
        ]);

        try {
            $user = User::findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        try {
            $workgroup = Workgroup::findOrFail($input['workgroup_id']);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        $userHasWorkgroup = UserHasWorkgroup::where([
            'user_id' => $user->id,
            'workgroup_id' => $input['workgroup_id'],
        ])->delete();

        if ($userHasWorkgroup) {
            return $this->OKResponse([]);
        }

        return $this->BadRequestResponse();
    }
}
