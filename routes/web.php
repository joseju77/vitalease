<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::middleware('auth')
    ->controller(UserController::class)
    ->name('users.')
    ->group(function () {
        Route::get('/users', 'index')->name('index')->middleware('can:viewAny,App\Models\User');
        Route::get('/users/create', 'create')->name('create')->middleware('can:create,App\Models\User');
        Route::post('/users', 'store')->name('store')->middleware('can:create,App\Models\User');
        Route::patch('/users/{user}/access', 'updateAccess')->name('access')->middleware('can:update,user');
    });
