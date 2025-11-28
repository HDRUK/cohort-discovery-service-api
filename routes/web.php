<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('auth/callback', [AuthController::class, 'callbackForAuthToken']);
Route::get('auth/callback2', [AuthController::class, 'callbackForUser']);
