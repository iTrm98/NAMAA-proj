<?php

use App\Http\Controllers\Api\Admin\ParkController as AdminParkController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Device\ReadingController as DeviceReadingController;
use App\Http\Controllers\Api\Municipality\ParkController as MunicipalityParkController;
use Illuminate\Support\Facades\Route;

/*
| NAMAA API. Bearer tokens only (Sanctum). auth:sanctum gives 401; then
| user.token / device.token / role:... give 403 (see EnsureUserToken,
| EnsureDeviceToken, EnsureRole).
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

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
});

Route::prefix('municipality')->middleware(['auth:sanctum', 'user.token', 'role:municipality'])->group(function () {
    Route::get('parks', [MunicipalityParkController::class, 'index']);
});
