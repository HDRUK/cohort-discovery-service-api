<?php

namespace App\Http\Middleware;

use SebastianBergmann\Timer\Timer;
use SebastianBergmann\Timer\ResourceUsageFormatter;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileRequest
{
    public function handle(
        Request $request,
        Closure $next
    ): JsonResponse|StreamedResponse|RedirectResponse|Response|BinaryFileResponse|SymfonyResponse {
        if (! config('profiling.profiler_active')) {
            return $next($request);
        }

        // Start timestamps for logging
        $startedAt = now();
        $startMicrotime = microtime(true);

        Log::info('Profiler started', [
            'method'      => $request->getMethod(),
            'path'        => $request->path(),
            'started_at'  => $startedAt->toIso8601String(),
        ]);

        // Create our profiler
        $timer = new Timer();
        $timer->start();

        // Process the request
        $response = $next($request);

        // End timestamps for logging
        $finishedAt = now();
        $endMicrotime = microtime(true);
        $durationMs = ($endMicrotime - $startMicrotime) * 1000;

        if ($response instanceof JsonResponse) {
            // Stop our profiler
            $duration = (new ResourceUsageFormatter())->resourceUsage($timer->stop());
            $parts = explode('\\', $request->route()->getAction()['controller']);
            $className = $parts[count($parts) - 1];

            $resourceUsed = [
                'explicitOperation' => $className,
                'operationResource' => $duration,
            ];

            $response->setData($response->getData(true) + [
                '_profiler' => $resourceUsed,
            ]);

            Log::info('Profiler finished in '.round($durationMs), [
                'method'        => $request->getMethod(),
                'path'          => $request->path(),
                'controller'    => $className,
                'finished_at'   => $finishedAt->toIso8601String(),
                'duration_ms'   => round($durationMs, 2),
                'resource_usage' => $duration,
            ]);
        } else {
            Log::info('Profiler finished in '.round($durationMs).' ms (non-JSON response)', [
                'method'      => $request->getMethod(),
                'path'        => $request->path(),
                'finished_at' => $finishedAt->toIso8601String(),
                'duration_ms' => round($durationMs, 2),
            ]);
        }

        // Return response (JSON with profiler or original response)
        return $response;
    }
}
