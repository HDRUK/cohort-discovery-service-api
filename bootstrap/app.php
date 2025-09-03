<?php

use App\Http\Middleware\DecodeJwt;
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
        // $middleware->aliasRouteMiddleware([
        //     'cbac' => \App\Http\Middleware\ClaimBasedAccessControl::class,
        //     'client_basic_auth' => \App\Http\Middleware\CollectionHostBasicAuth::class,
        // ]);
        $middleware->alias([
            'decode.jwt' => DecodeJwt::class,
        ]);
        $middleware->append(\App\Http\Middleware\LogHttpRequests::class);
        $middleware->append(\App\Http\Middleware\AuditRequests::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
