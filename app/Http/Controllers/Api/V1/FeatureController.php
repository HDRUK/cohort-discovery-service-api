<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Laravel\Pennant\Feature;
use App\Traits\Responses;
use Illuminate\Support\Facades\Auth;

class FeatureController extends Controller
{
    use Responses;

    public function index(Request $request): JsonResponse
    {
        if (!Auth::user()?->hasRole('admin')) {
            return $this->ForbiddenResponse();
        }

        return $this->OKResponse(Feature::all());
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (!Auth::user()?->hasRole('admin')) {
            return $this->ForbiddenResponse();
        }

        try {
            $input = $request->only(['enabled']);
            if ($input['enabled']) {
                Feature::activate($name);
                return $this->OKResponse([]);
            }

            Feature::deactivate($name);
            return $this->OKResponse([]);
        } catch (\Throwable $e) {
            return $this->NotFoundResponse();
        }
    }
}
