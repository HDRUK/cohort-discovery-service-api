<?php

use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\CollectionHostBasicAuth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('auth/callback', [AuthController::class, 'callback']);
Route::post('auth/callback/finalise', [AuthController::class, 'callbackFinalise'])
    ->name('auth.callback.finalise');

Route::get('/link_connector_api/task/status/{pid}', [TaskController::class, 'status'])
    ->name('task.status')
    ->middleware([
        'throttle:polling',
        CollectionHostBasicAuth::class,
    ]);
