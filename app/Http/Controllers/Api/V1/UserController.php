<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Models\User;
use App\Http\Controllers\Controller;

use App\Traits\Responses;

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
}
