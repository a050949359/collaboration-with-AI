<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\About\AboutController;
use App\Http\Controllers\About\ResumeContextController;
use App\Http\Controllers\Article\ArticleBrowseController;
use App\Http\Controllers\Article\ArticleEditController;
use App\Http\Controllers\Article\ArticleGenerationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegistController;
use App\Http\Controllers\Auth\SocialAccountController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Airports\AirportController;
use App\Http\Controllers\Airports\AirportStatsController;
use App\Http\Controllers\Airports\NearbyAirportController;
use App\Http\Controllers\Line\LineArticleController;
use App\Http\Controllers\Line\LineFriendController;
use App\Http\Middleware\EnsureAdmin;

Route::post('/about/ask', [AboutController::class, 'ask'])->middleware('throttle:4,1');

Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function () {
    Route::get('/about/context', [ResumeContextController::class, 'show']);
    Route::put('/about/context', [ResumeContextController::class, 'update']);
});

Route::group(['prefix' => 'auth'], function () {
    Route::post('/register', [RegistController::class, 'register']);
    Route::post('/login', [LoginController::class, 'login']);
    Route::get('/{provider}/redirect', [SocialAccountController::class, 'redirect'])->where(['provider' => 'google']);
    Route::get('/{provider}/callback', [SocialAccountController::class, 'callback'])->where(['provider' => 'google']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [LoginController::class, 'logout']);
        Route::get('/me', [LoginController::class, 'me']);

        // 點擊信件連結後觸發
        Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        // 重新寄送驗證信
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });
});

Route::middleware(['auth:sanctum', EnsureAdmin::class])->prefix('admin')->group(function () {
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::patch('/settings', [SettingsController::class, 'update']);
});

Route::prefix('v1/articles')->group(function () {
    Route::get('/', [ArticleBrowseController::class, 'publicIndex']);
    Route::get('/{article}', [ArticleBrowseController::class, 'publicShow']);
});

Route::middleware('auth:sanctum')->prefix('articles')->group(function () {
    Route::get('/', [ArticleBrowseController::class, 'authIndex']);
    Route::post('/', [ArticleGenerationController::class, 'store']);
    Route::get('/{article}', [ArticleGenerationController::class, 'show']);
    Route::put('/{article}', [ArticleEditController::class, 'update']);
    Route::delete('/{article}', [ArticleEditController::class, 'destroy']);
    Route::post('/{article}/generate-content', [ArticleGenerationController::class, 'generateContent']);
    Route::post('/{article}/generate-image', [ArticleGenerationController::class, 'generateImage']);
});

Route::prefix('v1/airports')->group(function () {
    Route::get('/',        [AirportController::class, 'index'])->middleware('throttle:60,1');  // 搜尋 + 篩選
    Route::get('/stats',   AirportStatsController::class);         // 統計
    Route::get('/nearby',  NearbyAirportController::class);        // 附近機場
    Route::get('/{ident}', [AirportController::class, 'show']);    // 單一機場（ident 或 iata）
});

Route::prefix('line/friends')->group(function () {
    Route::post('/add', [LineFriendController::class, 'add'])->middleware('throttle:20,1');
    Route::post('/remove', [LineFriendController::class, 'remove'])->middleware('throttle:20,1');
});

Route::prefix('line/articles')->group(function () {
    Route::post('/quick-generate', [LineArticleController::class, 'quickGenerate'])->middleware('throttle:20,1');
});

use App\Http\Controllers\Travel\PassengerController;
use App\Http\Controllers\Travel\TourController;
use App\Http\Controllers\Travel\BookingController;
use App\Http\Controllers\Travel\ExportController;
use App\Http\Controllers\Travel\StatsController;
use App\Http\Controllers\Travel\TourFlightController;
use App\Http\Controllers\Travel\TourHotelController;

Route::prefix('v1/tour')->group(function () {
    // 旅客（靜態路由必須在 {passenger} 之前）
    Route::get('/passengers/random', [PassengerController::class, 'random']);
    Route::get('/passengers/lookup', [PassengerController::class, 'lookup']);
    Route::get('/passengers/{passenger}', [PassengerController::class, 'show']);
    Route::get('/passengers', [PassengerController::class, 'index']);
    Route::post('/passengers', [PassengerController::class, 'store']);

    // 行程
    Route::get('/tours',      [TourController::class, 'index']);
    Route::post('/tours',     [TourController::class, 'store']);
    Route::put('/tours/{tour}', [TourController::class, 'update']);

    // 訂單
    Route::get('/bookings',   [BookingController::class, 'index']);
    Route::post('/bookings',  [BookingController::class, 'store']);

    // 匯出（Queue 主角）
    Route::get('/exports',               [ExportController::class, 'index']);
    Route::post('/exports',              [ExportController::class, 'store']);
    Route::get('/exports/{id}/status',   [ExportController::class, 'status']);
    Route::get('/exports/{id}/download', [ExportController::class, 'download']);

    Route::get('/stats', [StatsController::class, 'index']);

    Route::prefix('/{tour}')->group(function () {
        Route::get('/flights',           [TourFlightController::class, 'index']);
        Route::post('/flights',          [TourFlightController::class, 'store']);
        Route::delete('/flights/{flight}', [TourFlightController::class, 'destroy']);

        Route::get('/hotels',           [TourHotelController::class, 'index']);
        Route::post('/hotels',          [TourHotelController::class, 'store']);
        Route::delete('/hotels/{hotel}', [TourHotelController::class, 'destroy']);
    });
});