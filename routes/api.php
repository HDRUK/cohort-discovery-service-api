<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\ApplicationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

Route::post('/applications', [ApplicationController::class, 'store']);

Route::get('/v1/task/nextjob/{collection_id}', [TaskController::class, 'nextJob']);
Route::post('/v1/task/result/{uuid}/{collection_id}', [TaskController::class, 'receiveResult']);
Route::post('/v1/task', [TaskController::class, 'submitQueryAndCreateTasks']);
