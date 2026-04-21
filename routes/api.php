<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegistController;
use App\Http\Controllers\Auth\SocialAccountController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Airports\AirportController;
use App\Http\Controllers\Airports\AirportStatsController;
use App\Http\Controllers\Airports\NearbyAirportController;
use App\Http\Middleware\EnsureAdmin;

Route::group(['prefix' => 'auth'], function () {
    Route::post('/register', [RegistController::class, 'register']);
    Route::post('/login', [LoginController::class, 'login']);
    Route::get('/{provider}/redirect', [SocialAccountController::class, 'redirect'])->where(['provider' => 'google']);
    Route::get('/{provider}/callback', [SocialAccountController::class, 'callback'])->where(['provider' => 'google']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [LoginController::class, 'logout']);
        Route::get('/me', [LoginController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', EnsureAdmin::class])->prefix('admin')->group(function () {
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::patch('/settings', [SettingsController::class, 'update']);
});

Route::prefix('v1/airports')->group(function () {
    Route::get('/',        [AirportController::class, 'index'])->middleware('throttle:5,1');   // 搜尋 + 篩選
    Route::get('/stats',   AirportStatsController::class);         // 統計
    Route::get('/nearby',  NearbyAirportController::class);        // 附近機場
    Route::get('/{ident}', [AirportController::class, 'show']);    // 單一機場（ident 或 iata）
});
