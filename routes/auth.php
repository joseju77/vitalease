<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')
    ->controller(AuthController::class)
    ->name('auth.')
    ->group(function () {
        Route::post('/login', 'store')->name('login.store');
    });

Route::post('logout', [AuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('auth.logout');
