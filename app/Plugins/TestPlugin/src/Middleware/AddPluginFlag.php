<?php

namespace App\Plugins\TestPlugin\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AddPluginFlag
{
    public function handle(Request $request, Closure $next)
    {
        \Log::info('AddPluginFlag running');

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $data['plugin'] = true;
            $response->setData($data);
        }

        return $response;
    }
}
