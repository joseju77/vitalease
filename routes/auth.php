<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')
    ->controller(AuthController::class)
    ->name('auth.')
    ->group(function () {
        Route::get('/login', 'login')->name('login');
        Route::post('/login', 'store')->name('login.store');
        Route::get('/oauth/google/redirect', 'redirectToGoogle')->name('google.redirect');
        Route::get('/oauth/google/callback', 'handleGoogleCallback')->name('google.callback');
    });

Route::post('logout', [AuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('auth.logout');
