<?php

use App\Http\Middleware\DecodeJwt;
use App\Http\Middleware\ClaimBasedAccessControl;
use App\Http\Middleware\CollectionHostBasicAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'decode.jwt' => DecodeJwt::class,
            'cbac' => ClaimBasedAccessControl::class,
            //'client_basic_auth' => CollectionHostBasicAuth::class,
        ]);
        $middleware->append(\App\Http\Middleware\LogHttpRequests::class);
        $middleware->append(\App\Http\Middleware\AuditRequests::class);
        $middleware->append(\Hdruk\LaravelPluginCore\Middleware\InjectPlugins::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
