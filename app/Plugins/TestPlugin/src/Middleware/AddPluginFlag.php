<?php

namespace App\Plugins\TestPlugin\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Ignore()
 */
class AddPluginFlag
{
    public function handle(Request $request, Closure $next)
    {
        \Log::debug('AddPluginFlag running');

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $data['plugin'] = true;
            $response->setData($data);
        }

        return $response;
    }
}
