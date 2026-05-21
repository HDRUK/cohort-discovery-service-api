<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\RegressionTest;
use App\Services\RegressionTest\RegressionTestService;
use App\Traits\Responses;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegressionTestController extends Controller
{
    use AuthorizesRequests;
    use Responses;

    public function indexForAdmin(RegressionTestService $service): JsonResponse
    {
        $this->authorize('viewAnyForAdmin', RegressionTest::class);

        try {
            $tests = RegressionTest::with(['queryDefinition', 'collections'])
                ->whereNull('user_id')
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->OKResponse($service->listWithStats($tests));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@indexForAdmin - failed: '.$e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function indexForUser(RegressionTestService $service): JsonResponse
    {
        // Future: restrict to users linked via CustodianHasUser before enabling this endpoint.
        // $user = Auth::user();
        // if (!CustodianHasUser::where('user_id', $user->id)->exists()) {
        //     return $this->NotFoundResponse();
        // }

        try {
            $tests = RegressionTest::with(['queryDefinition', 'collections'])
                ->where('user_id', Auth::id())
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->OKResponse($service->listWithStats($tests));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@indexForUser - failed: '.$e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function store(ModelBackedRequest $request, RegressionTestService $service): JsonResponse
    {
        // Currently admin-only — creates global tests with user_id = null.
        // Future: remove this authorize check and pass Auth::id() when custodian users
        // are permitted to create their own scoped regression tests.
        $this->authorize('create', RegressionTest::class);

        $validated = $request->validated();

        try {
            $test = $service->create($validated);

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
                ->firstOrFail();

            $this->authorize('view', $test);

            return $this->OKResponse($service->getWithStats($test));
        } catch (\Throwable $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function update(ModelBackedRequest $request, RegressionTestService $service, string $pid): JsonResponse
    {
        $validated = $request->validated();

        try {
            $test = RegressionTest::where('pid', $pid)->firstOrFail();

            $this->authorize('update', $test);

            $updated = $service->update($test, $validated);

            return $this->OKResponse($service->getWithStats($updated));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@update - failed: '.$e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function destroy(string $pid): JsonResponse
    {
        try {
            $test = RegressionTest::where('pid', $pid)
                ->firstOrFail();

            $this->authorize('delete', $test);

            $test->delete();

            return $this->OKResponse([]);
        } catch (\Throwable $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function runAll(RegressionTestService $service, string $pid): JsonResponse
    {
        try {
            $test = RegressionTest::where('pid', $pid)->firstOrFail();
            $this->authorize('run', $test);

            return $this->OKResponse($service->run($test));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@run - failed: '.$e->getMessage());
            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function runSingle(RegressionTestService $service, string $pid, string $collectionPid): JsonResponse
    {
        try {
            $test = RegressionTest::where('pid', $pid)->firstOrFail();
            $this->authorize('run', $test);

            $collection = $test->collections()
                ->where('pid', $collectionPid)
                ->first();

            if (!$collection) {
                return $this->ValidationErrorResponse(['collection_pid' => ['Collection not linked to this regression test.']]);
            }

            return $this->OKResponse($service->run($test, $collection));
        } catch (\Throwable $e) {
            \Log::error('RegressionTestController@run - failed: '.$e->getMessage());
            return $this->ErrorResponse($e->getMessage());
        }
    }
}
