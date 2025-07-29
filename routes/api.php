<?php

use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/task/nextjob/{collection_id}', [TaskController::class, 'nextJob']);
Route::post('/v1/task/result/{uuid}/{collection_id}', [TaskController::class, 'receiveResult']);
Route::post('/v1/task', [TaskController::class, 'submitQueryAndCreateTasks']);
