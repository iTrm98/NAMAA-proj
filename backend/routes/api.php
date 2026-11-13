<?php

use App\Http\Controllers\Api\Admin\AlertController as AdminAlertController;
use App\Http\Controllers\Api\Admin\DetectionController as AdminDetectionController;
use App\Http\Controllers\Api\Admin\NodeController as AdminNodeController;
use App\Http\Controllers\Api\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Api\Admin\ParkClaimController as AdminParkClaimController;
use App\Http\Controllers\Api\Admin\ParkController as AdminParkController;
use App\Http\Controllers\Api\Admin\ParkSetupController as AdminParkSetupController;
use App\Http\Controllers\Api\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Api\Admin\TaskController as AdminTaskController;
use App\Http\Controllers\Api\Admin\WorkerController as AdminWorkerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Device\ReadingController as DeviceReadingController;
use App\Http\Controllers\Api\Admin\ImpactController as AdminImpactController;
use App\Http\Controllers\Api\Admin\NdviController as AdminNdviController;
use App\Http\Controllers\Api\Municipality\ParkController as MunicipalityParkController;
use App\Http\Controllers\Api\Worker\TaskController as WorkerTaskController;
use Illuminate\Support\Facades\Route;

/*
| NAMAA API. Bearer tokens only (Sanctum). auth:sanctum gives 401; then
| user.token / device.token / role:... give 403 (see EnsureUserToken,
| EnsureDeviceToken, EnsureRole). `demo` routes answer 404 unless DEMO_MODE.
| POST /api/broadcasting/auth is registered in bootstrap/app.php.
*/

Route::get('health', HealthController::class);
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
    Route::put('parks/{park}/outline', [AdminParkController::class, 'outline']);
    Route::post('parks/{park}/photo', [AdminParkController::class, 'storePhoto']);
    Route::delete('parks/{park}/photo', [AdminParkController::class, 'deletePhoto']);
    Route::get('parks/{park}/photo', [AdminParkController::class, 'photo']);
    Route::get('parks/{park}/readings', [AdminParkController::class, 'readings']);
    Route::post('parks/{park}/pump', [AdminParkController::class, 'pump']);
    Route::get('parks/{park}/detections', [AdminDetectionController::class, 'index']);
    Route::get('parks/{park}/ndvi', [AdminNdviController::class, 'index']);
    Route::get('parks/{park}/ndvi/pixels', [AdminNdviController::class, 'pixels']);
    Route::post('parks/{park}/green-zone/suggest', [AdminNdviController::class, 'suggest']);
    Route::get('parks/{park}/timeline', [AdminParkController::class, 'timeline']);
    Route::get('parks/{park}/claims', [AdminParkClaimController::class, 'index']);
    Route::post('parks/{park}/claims', [AdminParkClaimController::class, 'store']);
    Route::delete('claims/{claim}', [AdminParkClaimController::class, 'destroy']);
    Route::get('park-setup/osm', [AdminParkSetupController::class, 'osm']);
    Route::get('park-setup/selection', [AdminParkSetupController::class, 'selection']);
    Route::put('park-setup/selection', [AdminParkSetupController::class, 'saveSelection']);
    Route::get('overview', AdminOverviewController::class);
    Route::get('impact', AdminImpactController::class);
    Route::get('detections/{detection}/image', [AdminDetectionController::class, 'image']);

    Route::get('alerts', [AdminAlertController::class, 'index']);

    Route::get('tasks', [AdminTaskController::class, 'index']);
    Route::post('tasks', [AdminTaskController::class, 'store']);
    Route::get('tasks/{task}', [AdminTaskController::class, 'show']);
    Route::patch('tasks/{task}', [AdminTaskController::class, 'update']);
    Route::get('tasks/{task}/photo', [AdminTaskController::class, 'photo']);

    Route::get('workers', [AdminWorkerController::class, 'index']);

    Route::get('stand/live', [\App\Http\Controllers\Api\Admin\StandController::class, 'live']);
    Route::post('stand/pump', [\App\Http\Controllers\Api\Admin\StandController::class, 'pump']);
    Route::post('stand/calibrate', [\App\Http\Controllers\Api\Admin\StandController::class, 'calibrate']);
    Route::get('nodes', [AdminNodeController::class, 'index']);
    Route::post('nodes/{node}/calibration', [AdminNodeController::class, 'calibrate']);
    Route::post('nodes/{node}/relay-test', [AdminNodeController::class, 'relayTest']);
    Route::post('nodes/{node}/token', [AdminNodeController::class, 'token']);
    Route::patch('nodes/{node}/camera', [AdminNodeController::class, 'camera']);
    Route::post('nodes/{node}/frame', [AdminNodeController::class, 'frame'])->middleware('throttle:30,1');
    Route::post('nodes/{node}/camera-test', [AdminNodeController::class, 'cameraTest']);

    Route::get('system/status', [AdminSystemController::class, 'status']);
    Route::get('device-rejections', [AdminSystemController::class, 'rejections']);

    Route::get('demo/story', [DemoController::class, 'story'])->middleware('demo');
    Route::post('demo/scenario', [DemoController::class, 'scenario'])->middleware('demo');
    Route::post('demo/frame', [DemoController::class, 'frame'])->middleware('demo');
    Route::post('demo/ndvi', [DemoController::class, 'ndvi'])->middleware('demo');
    Route::get('demo/ndvi', [DemoController::class, 'ndviStatus'])->middleware('demo');
    Route::get('demo/health', [DemoController::class, 'health'])->middleware('demo');
});

Route::prefix('worker')->middleware(['auth:sanctum', 'user.token', 'role:worker'])->group(function () {
    Route::get('tasks', [WorkerTaskController::class, 'index']);
    Route::get('tasks/{task}', [WorkerTaskController::class, 'show']);
    Route::post('tasks/{task}/start', [WorkerTaskController::class, 'start']);
    Route::post('tasks/{task}/done', [WorkerTaskController::class, 'done']);
    Route::post('tasks/{task}/pump', [WorkerTaskController::class, 'pump']);
    Route::get('tasks/{task}/photo', [WorkerTaskController::class, 'photo']);
    Route::get('tasks/{task}/frame', [WorkerTaskController::class, 'frame']);
});

Route::prefix('municipality')->middleware(['auth:sanctum', 'user.token', 'role:municipality'])->group(function () {
    Route::get('parks', [MunicipalityParkController::class, 'index']);
    Route::get('parks/{park}', [MunicipalityParkController::class, 'show']);
    Route::get('parks/{park}/readings', [MunicipalityParkController::class, 'readings']);
    Route::get('parks/{park}/photo', [MunicipalityParkController::class, 'photo']);
    Route::get('parks/{park}/ndvi', [MunicipalityParkController::class, 'ndvi']);
    Route::get('parks/{park}/timeline', [MunicipalityParkController::class, 'timeline']);
    Route::get('overview', [MunicipalityParkController::class, 'overview']);
    Route::get('parks/{park}/detections', [MunicipalityParkController::class, 'detections']);
    Route::get('detections/{detection}/image', [MunicipalityParkController::class, 'detectionImage']);
    Route::get('alerts', [MunicipalityParkController::class, 'alerts']);
    Route::get('impact', [MunicipalityParkController::class, 'impact']);
});
