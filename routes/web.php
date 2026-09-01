<?php

use App\Enums\Permission;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\MedicalConsultationController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\MedicationDemandProjectionController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)
    ->middleware('auth')
    ->name('home');

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
        Route::get('/consultations/create', 'create')->name('create')->middleware('can:create,App\Models\MedicalConsultation');
        Route::post('/consultations', 'store')->name('store')->middleware('can:create,App\Models\MedicalConsultation');
        Route::get('/consultations/{consultation}', 'show')->name('show')->middleware('can:view,consultation');
        Route::get('/consultations/{consultation}/edit', 'edit')->name('edit')->middleware('can:update,consultation');
        Route::put('/consultations/{consultation}', 'update')->name('update')->middleware('can:update,consultation');
        Route::delete('/consultations/{consultation}', 'destroy')->name('destroy')->middleware('can:delete,consultation');
    });

Route::middleware('auth')
    ->controller(MedicationController::class)
    ->name('inventory.')
    ->group(function () {
        Route::get('/inventory', 'index')->name('index')->middleware('can:viewAny,App\Models\Medication');
        Route::post('/inventory/medications', 'store')->name('store')->middleware('can:create,App\Models\Medication');
        Route::get('/inventory/medications/{medication}', 'show')->name('show')->middleware('can:view,medication');
        Route::patch('/inventory/medications/{medication}', 'update')->name('update')->middleware('can:update,medication');
        Route::patch('/inventory/medications/{medication}/activate', 'activate')->name('activate')->middleware('can:update,medication');
        Route::patch('/inventory/medications/{medication}/deactivate', 'deactivate')->name('deactivate')->middleware('can:update,medication');
        Route::delete('/inventory/medications/{medication}', 'destroy')->name('destroy')->middleware('can:delete,medication');
    });

Route::middleware('auth')
    ->controller(InventoryMovementController::class)
    ->name('inventory.')
    ->group(function () {
        Route::post('/inventory/medications/{medication}/entries', 'storeEntry')
            ->name('entries.store')
            ->middleware('can:recordEntry,medication');
        Route::post('/inventory/medications/{medication}/adjustments', 'storeAdjustment')
            ->name('adjustments.store')
            ->middleware('can:recordAdjustment,medication');
    });

Route::middleware('auth')
    ->controller(DashboardController::class)
    ->name('dashboard.')
    ->group(function () {
        Route::get('/dashboard', 'index')
            ->name('index')
            ->middleware(['can:viewAny,App\Models\MedicalConsultation']);

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

Route::get('/reports/medication-demand', MedicationDemandProjectionController::class)
    ->middleware(['auth', 'can:'.Permission::ReportsGenerate->value])
    ->name('reports.medication-demand');
