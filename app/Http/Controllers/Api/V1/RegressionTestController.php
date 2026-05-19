<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\RegressionTest;
use App\Services\RegressionTest\RegressionTestService;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegressionTestController extends Controller
{
    use Responses;

    public function index(RegressionTestService $service): JsonResponse
    {
        try {
            $tests = RegressionTest::with(['queryDefinition', 'collections'])
                ->where('user_id', Auth::id())
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->OKResponse($service->listWithStats($tests));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@index - failed: '.$e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function store(ModelBackedRequest $request, RegressionTestService $service): JsonResponse
    {
        $validated = $request->validated();

        try {
            $test = $service->create($validated, Auth::id());

            return $this->CreatedResponse($service->getWithStats($test));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@store - failed: '.$e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function show(RegressionTestService $service, string $pid): JsonResponse
    {
        try {
            $test = RegressionTest::with(['queryDefinition', 'collections'])
                ->where('pid', $pid)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            return $this->OKResponse($service->getWithStats($test));
        } catch (\Throwable) {
            return $this->NotFoundResponse();
        }
    }

    public function update(ModelBackedRequest $request, RegressionTestService $service, string $pid): JsonResponse
    {
        $validated = $request->validated();

        try {
            $test = RegressionTest::where('pid', $pid)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $updated = $service->update($test, $validated);

            return $this->OKResponse($service->getWithStats($updated));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@update - failed: '.$e->getMessage());

            return $this->NotFoundResponse();
        }
    }

    public function destroy(string $pid): JsonResponse
    {
        try {
            $test = RegressionTest::where('pid', $pid)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $test->delete();

            return $this->OKResponse([]);
        } catch (\Throwable) {
            return $this->NotFoundResponse();
        }
    }

    public function run(RegressionTestService $service, string $pid): JsonResponse
    {
        try {
            $test = RegressionTest::where('pid', $pid)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            return $this->OKResponse($service->run($test));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@run - failed: '.$e->getMessage());

            return $this->NotFoundResponse();
        }
    }
}
