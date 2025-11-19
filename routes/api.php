<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\QueryController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CodeController;
use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkgroupController;
use App\Http\Controllers\Api\V1\CustodianController;
use App\Http\Controllers\Api\V1\CollectionHostController;
use App\Http\Controllers\Api\V1\ConceptSetController;
use App\Http\Controllers\Api\V1\OmopController;
use App\Http\Controllers\Api\V1\QueryParserController;
use App\Http\Controllers\Api\V1\CollectionConfigController;
use App\Http\Controllers\Api\V1\ServiceCallerController;
use App\Http\Middleware\CollectionHostBasicAuth;

Route::middleware(['decode.jwt'])->group(function () {
    Route::get('v1/user', [UserController::class, 'getMe']);
});

Route::post('/v1/parse-query', [QueryParserController::class, 'parse']);

Route::post('/v1/applications', [ApplicationController::class, 'store']);

Route::post('/v1/users/{id}/workgroup/add', [UserController::class, 'addToWorkgroup'])->middleware('cbac:admin');
Route::post('/v1/users/{id}/workgroup/remove', [UserController::class, 'removeFromWorkgroup'])->middleware('cbac:admin');

Route::get('/v1/users', [UserController::class, 'index']);
Route::get('/v1/users/{id}', [UserController::class, 'show']);

Route::middleware(['decode.jwt', 'cbac:admin'])->group(function () {
    Route::get('/v1/workgroups', [WorkgroupController::class, 'index']);
    Route::get('/v1/workgroups/{id}', [WorkgroupController::class, 'show']);
    Route::post('/v1/workgroups', [WorkgroupController::class, 'store']);
    Route::put('/v1/workgroups/{id}', [WorkgroupController::class, 'update']);
    Route::delete('/v1/workgroups/{id}', [WorkgroupController::class, 'destroy']);

    Route::get('/v1/workgroups/search/users', [WorkgroupController::class, 'usersByWorkgroup']);

    Route::get('/v1/custodians', [CustodianController::class, 'index']);
    Route::get('/v1/custodians/{id}', [CustodianController::class, 'show']);
    Route::post('/v1/custodians', [CustodianController::class, 'store']);
    Route::put('/v1/custodians/{id}', [CustodianController::class, 'update']);
    Route::delete('/v1/custodians/{id}', [CustodianController::class, 'destroy']);

    Route::get('/v1/collection_hosts', [CollectionHostController::class, 'index']);
    Route::get('/v1/collection_hosts/{id}', [CollectionHostController::class, 'show']);
    Route::post('/v1/collection_hosts', [CollectionHostController::class, 'store']);
    Route::put('/v1/collection_hosts/{id}', [CollectionHostController::class, 'update']);
    Route::delete('/v1/collection_hosts/{id}', [CollectionHostController::class, 'destroy']);

    Route::get('/v1/custodians/{custodianPid}/collection_hosts', [CollectionHostController::class, 'indexByCustodian']);

    Route::get('/v1/custodians/{custodianPid}/collections', [CollectionController::class, 'indexByCustodian']);
    Route::post('/v1/custodians/{custodianPid}/collections', [CollectionController::class, 'storeByCustodian']);
});

Route::get('/v1/task/nextjob/{collection_id}', [TaskController::class, 'nextJob'])
    ->name('task.nextjob')
    ->middleware([
        'throttle:polling',
        CollectionHostBasicAuth::class,
    ]);

Route::post('/v1/task/result/{uuid}/{collection_id}', [TaskController::class, 'receiveResult'])
    ->name('task.result')
    ->middleware([
        'throttle:polling',
        CollectionHostBasicAuth::class,
    ]);

Route::middleware(['decode.jwt'])->group(function () {
    Route::get('/v1/task/{pid}', [TaskController::class, 'getTask']);
    Route::get('/v1/tasks', [TaskController::class, 'getTasks']);

    // OLD //
    //Route::get('/v1/query/{pid}', [QueryController::class, 'getQuery']);
    Route::get('/v1/queries/latest', [QueryController::class, 'getLatestQuery']);
    //Route::get('/v1/queries', [QueryController::class, 'getQueries']);
    //Route::post('/v1/queries', [QueryController::class, 'submitQueryAndCreateTasks']);

    // NEW //
    Route::get('/v1/queries', [QueryController::class, 'index']);
    Route::get('/v1/query/{id}', [QueryController::class, 'show'])->whereNumber('id');
    Route::get('/v1/query/{pid}', [QueryController::class, 'show'])->whereUuid('pid');
    Route::get('/v1/query/re-run/{id}', [QueryController::class, 'duplicateAndReRun'])->whereNumber('id');
    Route::get('/v1/query/re-run/{pid}', [QueryController::class, 'duplicateAndReRun'])->whereUuid('pid');
    Route::post('/v1/queries', [QueryController::class, 'store']);
    Route::post('/v1/queries/test', [QueryController::class, 'test']);
    Route::put('/v1/query/{id}', [QueryController::class, 'update'])->whereNumber('id');
    Route::put('/v1/query/{pid}', [QueryController::class, 'update'])->whereUuid('pid');
    Route::delete('/v1/query/{id}', [QueryController::class, 'destroy'])->whereNumber('id');
    Route::delete('/v1/query/{pid}', [QueryController::class, 'destroy'])->whereUuid('pid');
    Route::get('/v1/queries/{pid}/download/{format}', [QueryController::class, 'download']);


    Route::get('/v1/concept_sets', [ConceptSetController::class, 'index']);
    Route::post('/v1/concept_sets', [ConceptSetController::class, 'store']);
    Route::get('/v1/concept_sets/{conceptSet}', [ConceptSetController::class, 'show']);
    Route::put('/v1/concept_sets/{conceptSet}', [ConceptSetController::class, 'update']);
    Route::delete('/v1/concept_sets/{conceptSet}', [ConceptSetController::class, 'destroy']);
    Route::delete('/v1/concept_sets/{conceptSet}/clear', [ConceptSetController::class, 'clear']);
    Route::post('/v1/concept_sets/{conceptSet}/attach/{conceptId}', [ConceptSetController::class, 'attachConcept']);
    Route::delete('/v1/concept_sets/{conceptSet}/detach/{conceptId}', [ConceptSetController::class, 'detachConcept']);


    Route::get('/v1/collections', [CollectionController::class, 'index']);
    Route::get('/v1/collections/{id}', [CollectionController::class, 'show']);
    Route::post('/v1/collections', [CollectionController::class, 'store']);
    Route::put('/v1/collections/{id}', [CollectionController::class, 'update']);
    Route::delete('/v1/collections/{id}', [CollectionController::class, 'destroy']);

    Route::get('/v1/collections/status/{status}', [CollectionController::class, 'getByStatus']);
    Route::get('/v1/collection/{pid}', [CollectionController::class, 'getCollection']);
    Route::get('/v1/collection/{pid}/codes', [CodeController::class, 'getCollectionCodeStats']);

    Route::get('/v1/collection_config', [CollectionConfigController::class, 'index']);
    Route::get('/v1/collection_config/{id}', [CollectionConfigController::class, 'show']);
    Route::put('/v1/collection_config/{id}', [CollectionConfigController::class, 'update']);
    Route::post('/v1/collection_config', [CollectionConfigController::class, 'store']);
    Route::delete('/v1/collection_config/{id}', [CollectionConfigController::class, 'destroy']);

    Route::get('/v1/codes', [CodeController::class, 'getAllCodes']);
    Route::get('/v1/codes/stats', [CodeController::class, 'getCodeStats']);
    Route::get('/v1/codes/{domain}', [CodeController::class, 'getCodes']);


    Route::get('/v1/omop/concept/{concept_id}', [OmopController::class, 'getConcept']);
    Route::get('/v1/omop/{concept_id}/find_similar', [OmopController::class, 'getPeersAtLevel']);
    Route::get('/v1/omop/concepts/search', [OmopController::class, 'searchConcepts']);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [\App\Http\Controllers\Api\V1\LocalAuthController::class, 'login']);
    Route::post('/logout', [\App\Http\Controllers\Api\V1\LocalAuthController::class, 'logout']);
});

Route::get('/status', function (Request $request) {
    return response()->json([
        'message' => 'alive',
    ], 200);
});

Route::post('/v1/services/caller/{command}', [ServiceCallerController::class, 'dispatch']);
