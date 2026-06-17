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
        Route::post('/users', 'store')->name('store')->middleware('can:create,App\Models\User');
        Route::patch('/users/{user}', 'update')->name('update')->middleware('can:update,user');
        Route::put('/users/{user}/authorization', 'updateAuthorization')->name('authorization')->middleware('can:update,user');
    });
