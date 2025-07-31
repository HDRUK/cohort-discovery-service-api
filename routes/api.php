<?php

use App\Http\Controllers\Api\V1\QueryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\ApplicationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

Route::post('/applications', [ApplicationController::class, 'store']);


Route::middleware('throttle:polling')->group(function () {
    Route::get('/v1/task/nextjob/{collection_id}', [TaskController::class, 'nextJob']);
    Route::post('/v1/task/result/{uuid}/{collection_id}', [TaskController::class, 'receiveResult']);
});

Route::post('/v1/task', [TaskController::class, 'submitQueryAndCreateTasks']);
Route::get('/v1/task/{pid}', [TaskController::class, 'getTask']);
Route::get('/v1/tasks', [TaskController::class, 'getTasks']);


Route::get('/v1/query/{pid}', [QueryController::class, 'getQuery']);
Route::get('/v1/queries', [QueryController::class, 'getQueries']);


Route::get('/v1/collection/{pid}', [CollectionController::class, 'getCollection']);
Route::get('/v1/collections', [CollectionController::class, 'getCollections']);


Route::get('/status', function (Request $request) {
    return response()->json([
        'message' => 'alive',
    ], 200);
});
