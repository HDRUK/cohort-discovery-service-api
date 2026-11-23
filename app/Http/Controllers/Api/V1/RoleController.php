<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use Responses;

    public function index(): JsonResponse
    {
        if (! Auth::user()?->hasRole('admin')) {
            return $this->ForbiddenResponse();
        }

        return $this->OKResponse(Role::orderBy('name')->get(['id', 'name']));
    }
}
