<?php

use App\Http\Controllers\Api\Admin\AlertController as AdminAlertController;
use App\Http\Controllers\Api\Admin\ParkController as AdminParkController;
use App\Http\Controllers\Api\Admin\TaskController as AdminTaskController;
use App\Http\Controllers\Api\Admin\WorkerController as AdminWorkerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\Device\ReadingController as DeviceReadingController;
use App\Http\Controllers\Api\Municipality\ParkController as MunicipalityParkController;
use App\Http\Controllers\Api\Worker\TaskController as WorkerTaskController;
use Illuminate\Support\Facades\Route;

/*
| NAMAA API. Bearer tokens only (Sanctum). auth:sanctum gives 401; then
| user.token / device.token / role:... give 403 (see EnsureUserToken,
| EnsureDeviceToken, EnsureRole). `demo` routes answer 404 unless DEMO_MODE.
| POST /api/broadcasting/auth is registered in bootstrap/app.php.
*/

Route::get('demo/status', [DemoController::class, 'status']);

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('demo-login', [DemoController::class, 'login'])->middleware(['demo', 'throttle:demo-login']);

    Route::middleware(['auth:sanctum', 'user.token'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::prefix('device')->middleware(['auth:sanctum', 'device.token', 'throttle:device'])->group(function () {
    Route::post('readings', [DeviceReadingController::class, 'store']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'user.token', 'role:admin'])->group(function () {
    Route::get('parks', [AdminParkController::class, 'index']);
    Route::get('parks/{park}', [AdminParkController::class, 'show']);
    Route::patch('parks/{park}', [AdminParkController::class, 'update']);
    Route::get('parks/{park}/readings', [AdminParkController::class, 'readings']);
    Route::post('parks/{park}/pump', [AdminParkController::class, 'pump']);

    Route::get('alerts', [AdminAlertController::class, 'index']);

    Route::get('tasks', [AdminTaskController::class, 'index']);
    Route::post('tasks', [AdminTaskController::class, 'store']);
    Route::get('tasks/{task}', [AdminTaskController::class, 'show']);
    Route::patch('tasks/{task}', [AdminTaskController::class, 'update']);
    Route::get('tasks/{task}/photo', [AdminTaskController::class, 'photo']);

    Route::get('workers', [AdminWorkerController::class, 'index']);

    Route::post('demo/scenario', [DemoController::class, 'scenario'])->middleware('demo');
});

Route::prefix('worker')->middleware(['auth:sanctum', 'user.token', 'role:worker'])->group(function () {
    Route::get('tasks', [WorkerTaskController::class, 'index']);
    Route::get('tasks/{task}', [WorkerTaskController::class, 'show']);
    Route::post('tasks/{task}/start', [WorkerTaskController::class, 'start']);
    Route::post('tasks/{task}/done', [WorkerTaskController::class, 'done']);
    Route::post('tasks/{task}/pump', [WorkerTaskController::class, 'pump']);
    Route::get('tasks/{task}/photo', [WorkerTaskController::class, 'photo']);
});

Route::prefix('municipality')->middleware(['auth:sanctum', 'user.token', 'role:municipality'])->group(function () {
    Route::get('parks', [MunicipalityParkController::class, 'index']);
});
