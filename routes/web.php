<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('auth/callback', [AuthController::class, 'callbackForAuthToken']);
Route::get('auth/callback2', [AuthController::class, 'callbackForUser']);
