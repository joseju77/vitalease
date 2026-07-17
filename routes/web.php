<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MedicalConsultationController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::controller(PatientController::class)
    ->name('patients.')
    ->group(function () {
        Route::get('/patients/register', 'create')->name('register');
        Route::post('/patients/register', 'store')->name('register.store')->middleware('throttle:5,1');
        Route::get('/patients/register/neighborhoods', 'neighborhoods')
            ->name('register.neighborhoods')
            ->middleware('throttle:30,1');
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

Route::middleware('auth')
    ->controller(RoleController::class)
    ->name('roles.')
    ->group(function () {
        Route::get('/roles', 'index')->name('index')->middleware('can:viewAny,Spatie\Permission\Models\Role');
        Route::post('/roles', 'store')->name('store')->middleware('can:create,Spatie\Permission\Models\Role');
        Route::patch('/roles/{role}', 'update')->name('update')->middleware('can:update,role');
        Route::delete('/roles/{role}', 'destroy')->name('destroy')->middleware('can:delete,role');
    });

Route::middleware('auth')
    ->controller(MedicalConsultationController::class)
    ->name('consultations.')
    ->group(function () {
        Route::post('/consultations', 'store')->name('store')->middleware('can:create,App\Models\MedicalConsultation');
        Route::put('/consultations/{consultation}', 'update')->name('update')->middleware('can:update,consultation');
        Route::delete('/consultations/{consultation}', 'destroy')->name('destroy')->middleware('can:delete,consultation');
    });

Route::middleware('auth')
    ->controller(DashboardController::class)
    ->name('dashboard.')
    ->group(function () {
        Route::get('/dashboard/patients/search', 'searchPatients')
            ->name('patients.search')
            ->middleware(['can:viewAny,App\Models\Patient']);
        Route::get('/dashboard/patients/{patient}', 'patientSummary')
            ->name('patients.summary')
            ->middleware(['can:view,patient', 'can:viewAny,App\Models\MedicalConsultation']);
        Route::get('/dashboard/consultations', 'latestConsultations')
            ->name('consultations.latest')
            ->middleware('can:viewAny,App\Models\MedicalConsultation');
    });
