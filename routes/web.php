<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FuelOperationController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/forgot-password', [AuthController::class, 'requestPasswordReset'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'resetPasswordForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/fuel-prices', [FuelOperationController::class, 'prices'])->name('prices.index');
    Route::get('/sales', [FuelOperationController::class, 'sales'])->name('sales.index');
    Route::post('/sales', [FuelOperationController::class, 'storeSale'])->name('sales.store');

    Route::get('/deliveries', [FuelOperationController::class, 'deliveries'])->name('deliveries.index');
    Route::post('/deliveries', [FuelOperationController::class, 'storeDelivery'])->name('deliveries.store');
    Route::post('/deliveries/{delivery}/approve', [FuelOperationController::class, 'approveDelivery'])
        ->middleware('role:owner,manager')
        ->name('deliveries.approve');

    Route::get('/adjustments', [FuelOperationController::class, 'adjustments'])->name('adjustments.index');
    Route::post('/adjustments', [FuelOperationController::class, 'storeAdjustment'])->name('adjustments.store');
    Route::post('/adjustments/{adjustment}/approve', [FuelOperationController::class, 'approveAdjustment'])
        ->middleware('role:owner,manager')
        ->name('adjustments.approve');

    Route::middleware('role:owner,manager')->group(function (): void {
        Route::get('/setup', [SetupController::class, 'index'])->name('setup.index');
        Route::post('/setup/tanks', [SetupController::class, 'storeTank'])->name('setup.tanks');
        Route::put('/setup/tanks/{tank}', [SetupController::class, 'updateTank'])
            ->name('setup.tanks.update');
        Route::post('/setup/nozzles', [SetupController::class, 'storeNozzle'])->name('setup.nozzles');
        Route::post('/setup/prices', [SetupController::class, 'storePrice'])->name('setup.prices');
        Route::put('/setup/prices/{price}', [SetupController::class, 'updatePrice'])
            ->name('setup.prices.update');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
    });
    Route::post('/setup/products', [SetupController::class, 'storeProduct'])
        ->middleware('role:owner')
        ->name('setup.products');
});
