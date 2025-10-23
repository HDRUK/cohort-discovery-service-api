<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Console\Commands\Dispatchers\ApiCommandDispatcher;
use App\Traits\Responses;

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
