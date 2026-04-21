<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\Admin\SettingsController;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/airports', 'Airports')->name('airports');
Route::get('/login', fn () => Inertia::render('Auth/Login'))->name('login');
Route::get('/register', fn () => Inertia::render('Auth/Register'))->name('register');
Route::get('/avatar/default/{seed}.svg', [AvatarController::class, 'default'])->name('avatar.default');

// Admin Inertia shell — auth guard is handled client-side via Bearer token
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
});
