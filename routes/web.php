<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Controllers\Article\ArticlePageController;
use App\Http\Controllers\MiniOrch\MiniOrchController;
use Illuminate\Support\Facades\Route;

Route::prefix('app')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::inertia('/airports', 'Airports')->name('airports');
    Route::inertia('/airlines', 'Airlines')->name('airlines');
    Route::inertia('/countries', 'Countries')->name('countries');
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

    Route::get('/mini-orch', [MiniOrchController::class, 'page'])->name('mini-orch');
    Route::inertia('/ws-lab', 'WsLab')->name('ws-lab');
    Route::inertia('/gacha', 'Gacha')->name('gacha');
    Route::inertia('/mcp', 'Mcp')->name('mcp');
    Route::inertia('/memory', 'MemoryGraph')->name('memory');
    Route::inertia('/computer-vision', 'ComputerVision')->name('computer-vision');
    Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function () {
        Route::inertia('/story-relay', 'StoryRelay')->name('story-relay');
    });

    Route::redirect('/login', '/app/')->name('login');
    Route::redirect('/register', '/app/')->name('register');
    Route::inertia('/forgot-password', 'Auth/ForgotPassword')->name('forgot-password');
    Route::inertia('/reset-password', 'Auth/ResetPassword')->name('reset-password');
    Route::get('/avatar/default/{seed}', [AvatarController::class, 'default'])->name('avatar.default');

    // 信箱驗證結果頁
    Route::inertia('/verify-result', 'Auth/VerifyResult')->name('verify.result');

    Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function () {
        Route::inertia('/admin', 'Admin/System')->name('admin');
    });
});
