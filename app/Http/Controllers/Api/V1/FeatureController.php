<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Laravel\Pennant\Feature;
use App\Traits\Responses;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class FeatureController extends Controller
{
    use Responses;

    public function index(Request $request): JsonResponse
    {
        $featureNames = \DB::table('features')
            ->distinct('name')
            ->orderBy('name')
            ->pluck('name');

        // Global scope for now, may enable user scoping in the future
        // $scope = Auth::user();
        $scope = null;

        $data = collect($featureNames)
            ->mapWithKeys(fn (string $name) => [
                $name => (bool) Feature::for($scope)->value($name),
            ])
            ->all();

        return $this->OKResponse($data);
    }

    public function update(Request $request, string $name): JsonResponse
    {
        if (!Auth::user()?->hasRole('admin')) {
            return $this->ForbiddenResponse();
        }

        try {
            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        // Global scope for now, may enable user scoping in the future
        // $scope = Auth::user();
        $scope = null;
        $before = (bool) Feature::for($scope)->value($name);
        $after = (bool) $validated['enabled'];

        if ($after) {
            Feature::activateForEveryone($name);
        } else {
            Feature::deactivateForEveryone($name);
        }

        activity('feature_flags')
            ->causedBy(Auth::user())
            ->withProperties([
                'feature' => $name,
                'before' => ['enabled' => $before],
                'after' => ['enabled' => $after],
            ])
            ->log('feature_flag_updated');

        return $this->OKResponse([]);

    }
}
