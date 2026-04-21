<?php

use Inertia\Inertia;
use Illuminate\Support\Facades\Route;
Route::inertia('/', 'Welcome')->name('home');
Route::get('/login', fn () => Inertia::render('Auth/Login'))->name('login');
Route::get('/register', fn () => Inertia::render('Auth/Register'))->name('register');
