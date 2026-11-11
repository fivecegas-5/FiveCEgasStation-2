<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchContextController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FuelCreditController;
use App\Http\Controllers\FuelOperationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesImportController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkspaceController;
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

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/api/workspace', WorkspaceController::class)->name('api.workspace');
    Route::post('/branch-context', [BranchContextController::class, 'update'])
        ->middleware('role:owner')
        ->name('branch-context.update');
    Route::middleware('role:owner')->group(function (): void {
        Route::get('/api/sales/import-batches', [SalesImportController::class, 'index'])
            ->name('api.sales.import-batches.index');
        Route::get('/api/sales/import/template.csv', [SalesImportController::class, 'template'])
            ->name('api.sales.import.template');
        Route::post('/api/sales/import/preview', [SalesImportController::class, 'preview'])
            ->name('api.sales.import.preview');
        Route::get('/api/sales/import-batches/{batch}', [SalesImportController::class, 'show'])
            ->name('api.sales.import-batches.show');
        Route::post('/api/sales/import-batches/{batch}/confirm', [SalesImportController::class, 'confirm'])
            ->name('api.sales.import-batches.confirm');
        Route::get('/credit', static fn () => view('operations.credit'))->name('credit.index');
    });
    Route::get('/api/notifications', [NotificationController::class, 'index'])
        ->name('api.notifications.index');
    Route::patch('/api/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('api.notifications.read-all');
    Route::patch('/api/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('api.notifications.read');
    Route::get('/sales', [FuelOperationController::class, 'sales'])
        ->middleware('role:owner,staff')
        ->name('sales.index');
    Route::get('/inventory', static fn () => view('operations.inventory'))
        ->middleware('role:owner,staff')
        ->name('inventory.index');
    Route::get('/transactions', static fn () => view('operations.transactions'))
        ->middleware('role:owner,staff')
        ->name('transactions.index');
    Route::post('/sales', [FuelOperationController::class, 'storeSale'])
        ->middleware('role:owner,staff')
        ->name('sales.store');
    Route::get('/', DashboardController::class)
        ->middleware('role:owner,staff')
        ->name('dashboard');

    Route::middleware('role:owner')->group(function (): void {
        Route::get('/reports', static fn () => view('operations.reports'))->name('reports.index');
        Route::get('/api/dashboard', [DashboardController::class, 'data'])->name('api.dashboard');

        Route::get('/fuel-prices', [FuelOperationController::class, 'prices'])->name('prices.index');
        Route::put('/sales/{sale}', [FuelOperationController::class, 'updateSale'])->name('sales.update');
        Route::post('/sales/{sale}/void', [FuelOperationController::class, 'voidSale'])->name('sales.void');

        Route::get('/deliveries', [FuelOperationController::class, 'deliveries'])->name('deliveries.index');
        Route::post('/deliveries', [FuelOperationController::class, 'storeDelivery'])->name('deliveries.store');
        Route::put('/deliveries/{delivery}', [FuelOperationController::class, 'updateDelivery'])->name('deliveries.update');
        Route::post('/deliveries/{delivery}/void', [FuelOperationController::class, 'voidDelivery'])->name('deliveries.void');
        Route::post('/deliveries/{delivery}/approve', [FuelOperationController::class, 'approveDelivery'])->name('deliveries.approve');
        Route::post('/deliveries/{delivery}/reject', [FuelOperationController::class, 'rejectDelivery'])->name('deliveries.reject');
        Route::get('/deliveries/{delivery}/invoice', [FuelOperationController::class, 'downloadDeliveryInvoice'])
            ->name('deliveries.invoice');

        Route::get('/adjustments', [FuelOperationController::class, 'adjustments'])->name('adjustments.index');
        Route::post('/adjustments', [FuelOperationController::class, 'storeAdjustment'])->name('adjustments.store');
        Route::put('/adjustments/{adjustment}', [FuelOperationController::class, 'updateAdjustment'])->name('adjustments.update');
        Route::post('/adjustments/{adjustment}/void', [FuelOperationController::class, 'voidAdjustment'])->name('adjustments.void');
        Route::post('/adjustments/{adjustment}/approve', [FuelOperationController::class, 'approveAdjustment'])->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/reject', [FuelOperationController::class, 'rejectAdjustment'])->name('adjustments.reject');

        Route::get('/setup', [SetupController::class, 'index'])->name('setup.index');
        Route::post('/setup/branches', [SetupController::class, 'storeBranch'])->name('setup.branches');
        Route::put('/setup/branches/{branch}', [SetupController::class, 'updateBranch'])->name('setup.branches.update');
        Route::patch('/setup/branches/{branch}/status', [SetupController::class, 'updateBranchStatus'])->name('setup.branches.status');
        Route::post('/setup/tanks', [SetupController::class, 'storeTank'])->name('setup.tanks');
        Route::put('/setup/tanks/{tank}', [SetupController::class, 'updateTank'])
            ->name('setup.tanks.update');
        Route::patch('/setup/tanks/{tank}/status', [SetupController::class, 'updateTankStatus'])
            ->name('setup.tanks.status');
        Route::post('/setup/nozzles', [SetupController::class, 'storeNozzle'])->name('setup.nozzles');
        Route::post('/setup/pumps', [SetupController::class, 'storePump'])->name('setup.pumps');
        Route::put('/setup/pumps/{pump}', [SetupController::class, 'updatePump'])
            ->name('setup.pumps.update');
        Route::put('/setup/nozzles/{nozzle}', [SetupController::class, 'updateNozzle'])
            ->name('setup.nozzles.update');
        Route::patch('/setup/pumps/{pump}/status', [SetupController::class, 'updatePumpStatus'])
            ->name('setup.pumps.status');
        Route::patch('/setup/nozzles/{nozzle}/status', [SetupController::class, 'updateNozzleStatus'])
            ->name('setup.nozzles.status');
        Route::post('/setup/prices', [SetupController::class, 'storePrice'])->name('setup.prices');
        Route::put('/setup/prices/{price}', [SetupController::class, 'updatePrice'])
            ->name('setup.prices.update');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])
            ->name('users.status');

        Route::get('/api/shifts/active', [ShiftController::class, 'active'])->name('api.shifts.active');
        Route::post('/api/shifts', [ShiftController::class, 'open'])->name('api.shifts.open');
        Route::post('/api/shifts/{shift}/close', [ShiftController::class, 'close'])->name('api.shifts.close');
        Route::get('/api/reports/summary', [ReportController::class, 'summary'])->name('api.reports.summary');
        Route::get('/api/reports/sales', [ReportController::class, 'sales'])->name('api.reports.sales');
        Route::get('/api/reports/inventory-movements', [ReportController::class, 'inventoryMovements'])
            ->name('api.reports.inventory-movements');
        Route::get('/api/reports/sales.csv', [ReportController::class, 'salesCsv'])->name('api.reports.sales.csv');
        Route::get('/api/reports/sales/monthly.csv', [ReportController::class, 'monthlySalesCsv'])
            ->name('api.reports.sales.monthly');
        Route::get('/api/reports/audit-logs', [ReportController::class, 'auditLogs'])
            ->name('api.reports.audit-logs');
        Route::get('/api/alerts/low-stock', [ReportController::class, 'lowStockAlerts'])
            ->name('api.alerts.low-stock');

        Route::get('/api/credit/customers', [FuelCreditController::class, 'customers'])
            ->name('api.credit.customers.index');
        Route::get('/api/credit/customers/export.csv', [FuelCreditController::class, 'exportCustomers'])
            ->name('api.credit.customers.export');
        Route::post('/api/credit/customers/import', [FuelCreditController::class, 'importCustomers'])
            ->name('api.credit.customers.import');
        Route::post('/api/credit/customers', [FuelCreditController::class, 'storeCustomer'])
            ->name('api.credit.customers.store');
        Route::put('/api/credit/customers/{customer}', [FuelCreditController::class, 'updateCustomer'])
            ->name('api.credit.customers.update');
        Route::patch('/api/credit/customers/{customer}/status', [FuelCreditController::class, 'updateCustomerStatus'])
            ->name('api.credit.customers.status');
        Route::get('/api/credit/agreements', [FuelCreditController::class, 'agreements'])
            ->name('api.credit.agreements.index');
        Route::get('/api/credit/agreements/{agreement}', [FuelCreditController::class, 'showAgreement'])
            ->name('api.credit.agreements.show');
        Route::post('/api/credit/agreements', [FuelCreditController::class, 'storeAgreement'])
            ->name('api.credit.agreements.store');
        Route::post('/api/credit/agreements/{agreement}/void', [FuelCreditController::class, 'voidAgreement'])
            ->name('api.credit.agreements.void');
        Route::post('/api/credit/agreements/{agreement}/payments', [FuelCreditController::class, 'storePayment'])
            ->name('api.credit.payments.store');
        Route::post('/api/credit/payments/{payment}/void', [FuelCreditController::class, 'voidPayment'])
            ->name('api.credit.payments.void');
        Route::get('/api/credit/agreements/{agreement}/document', [FuelCreditController::class, 'agreementDocument'])
            ->name('api.credit.agreements.document');
    });

    Route::post('/setup/products', [SetupController::class, 'storeProduct'])
        ->middleware('role:owner')
        ->name('setup.products');
    Route::put('/setup/products/{product}', [SetupController::class, 'updateProduct'])
        ->middleware('role:owner')
        ->name('setup.products.update');
    Route::patch('/setup/products/{product}/status', [SetupController::class, 'updateProductStatus'])
        ->middleware('role:owner')
        ->name('setup.products.status');
});
