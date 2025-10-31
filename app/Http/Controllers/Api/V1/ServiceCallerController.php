<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Console\Commands\Dispatchers\ApiCommandDispatcher;
use App\Traits\Responses;
use App\Http\Controllers\Controller;

class ServiceCallerController extends Controller
{
    use Responses;

    public function dispatch(Request $request, string $command): JsonResponse
    {
        $result = app(ApiCommandDispatcher::class)->run($command, $request->all());

        return $this->OKResponse([
            'data' => $result,
        ]);
    }
}
