<?php

namespace App\Http\Controllers\Api\V1;

use App\Console\Commands\Dispatchers\ApiCommandDispatcher;
use App\Http\Controllers\Controller;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
