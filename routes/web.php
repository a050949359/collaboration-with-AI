<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Article\ArticlePageController;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;

Route::prefix('app')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::inertia('/airports', 'Airports')->name('airports');
    Route::inertia('/airlines', 'Airlines')->name('airlines');
    Route::inertia('/countries', 'Countries')->name('countries');
    Route::inertia('/city-search', 'CitySearch')->name('city-search');
    Route::inertia('/about', 'About')->name('about');
    Route::inertia('/linebot', 'LineBot')->name('linebot');
    Route::inertia('/tour-playground', 'TourPlayground')->name('tour-playground');
    Route::inertia('/articles', 'Articles/Index')->name('articles.index');
    Route::get('/articles/{article}', [ArticlePageController::class, 'show'])
        ->whereNumber('article')
        ->name('articles.show');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/articles/generate', [ArticlePageController::class, 'generateNew'])
            ->name('articles.generate.new');

        Route::get('/articles/{article}/edit', [ArticlePageController::class, 'edit'])
            ->whereNumber('article')
            ->name('articles.edit');
    });

    Route::inertia('/login', 'Auth/Login')->name('login');
    Route::inertia('/register', 'Auth/Register')->name('register');
    Route::get('/avatar/default/{seed}', [AvatarController::class, 'default'])->name('avatar.default');

    // 信箱驗證結果頁
    Route::inertia('/verify-result', 'Auth/VerifyResult')->name('verify.result');

    // Admin Inertia shell — auth guard is handled client-side via Bearer token
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    });
});
