<?php

use App\Http\Controllers\Api\V1\ArchiveController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BoxController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExchangeRateController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OperationController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VaultController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('settings/public', [SettingController::class, 'publicSettings'])->name('settings.public');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:sanctum', 'active', 'api.audit'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::put('auth/change-password', [AuthController::class, 'changePassword'])->name('auth.change-password');

        Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
        Route::get('dashboard/chart', [DashboardController::class, 'chart'])->name('dashboard.chart');

        Route::get('transactions/daily-summary', [TransactionController::class, 'dailySummary'])->name('transactions.daily-summary');
        Route::apiResource('transactions', TransactionController::class);
        Route::patch('transactions/{id}/restore', [TransactionController::class, 'restore'])
            ->middleware('role:owner,sanctum')
            ->name('transactions.restore');
        Route::delete('transactions/{id}/force', [TransactionController::class, 'forceDelete'])
            ->middleware('role:owner,sanctum')
            ->name('transactions.force-delete');

        Route::get('operations/{operation}/receipt', [OperationController::class, 'receipt'])->name('operations.receipt');
        Route::apiResource('operations', OperationController::class);

        Route::get('customers/{customer}/transactions', [CustomerController::class, 'transactions'])->name('customers.transactions');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('customers/{customer}/balance', [CustomerController::class, 'balance'])->name('customers.balance');
        Route::apiResource('customers', CustomerController::class)->except(['store']);
        Route::patch('customers/{id}/restore', [CustomerController::class, 'restore'])
            ->middleware('role:owner,sanctum')
            ->name('customers.restore');
        Route::delete('customers/{id}/force', [CustomerController::class, 'forceDelete'])
            ->middleware('role:owner,sanctum')
            ->name('customers.force-delete');

        Route::patch('vaults/{vault}/set-balance', [VaultController::class, 'setBalance'])
            ->middleware('role:owner,sanctum')
            ->name('vaults.set-balance');
        Route::get('vaults/{vault}/transactions', [VaultController::class, 'transactions'])->name('vaults.transactions');
        Route::get('vaults/{vault}/summary', [VaultController::class, 'summary'])->name('vaults.summary');
        Route::apiResource('vaults', VaultController::class)->only(['index', 'show', 'update']);

        Route::patch('boxes/{box}/balance', [BoxController::class, 'balance'])->name('boxes.balance');
        Route::get('boxes/{box}/logs', [BoxController::class, 'logs'])->name('boxes.logs');
        Route::get('boxes', [BoxController::class, 'index'])->name('boxes.index');
        Route::post('boxes', [BoxController::class, 'store'])->name('boxes.store');
        Route::get('boxes/{box}', [BoxController::class, 'show'])->name('boxes.show');
        Route::put('boxes/{box}', [BoxController::class, 'update'])->name('boxes.update');
        Route::delete('boxes/{box}', [BoxController::class, 'destroy'])->name('boxes.destroy');

        Route::put('currencies/{code}/rate', [ExchangeRateController::class, 'update'])->name('currencies.rate.update');
        Route::apiResource('currencies', CurrencyController::class);

        Route::post('exchange-rates/bulk-update', [ExchangeRateController::class, 'bulkUpdate'])->name('exchange-rates.bulk-update');
        Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');

        Route::get('reports/daily', [ReportController::class, 'daily'])->name('reports.daily');
        Route::get('reports/monthly', [ReportController::class, 'monthly'])->name('reports.monthly');
        Route::get('reports/users-comparison', [ReportController::class, 'usersComparison'])
            ->middleware('role:owner,sanctum')
            ->name('reports.users-comparison');
        Route::get('reports/customer/{id}/statement', [ReportController::class, 'customerStatement'])->name('reports.customer.statement');
        Route::post('reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('reports/export/{job_id}/status', [ReportController::class, 'status'])->name('reports.export.status');
        Route::get('reports/export/{job_id}/download', [ReportController::class, 'download'])->name('reports.export.download');
        Route::get('receipts/{transaction_id}', [ReceiptController::class, 'show'])->name('receipts.show');

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('role:owner,sanctum')
            ->name('settings.index');
        Route::get('settings/{group}', [SettingController::class, 'group'])->name('settings.group');
        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('role:owner,sanctum')
            ->name('settings.update');
        Route::post('settings/reset/{group}', [SettingController::class, 'reset'])
            ->middleware('role:owner,sanctum')
            ->name('settings.reset');

        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::put('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::put('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

        Route::get('archive', [ArchiveController::class, 'index'])->name('archive.index');
        Route::get('archive/{archive}', [ArchiveController::class, 'show'])->name('archive.show');
        Route::post('archive/{archive}/restore', [ArchiveController::class, 'restore'])
            ->middleware('role:owner,sanctum')
            ->name('archive.restore');

        Route::middleware('role:owner,sanctum')->group(function (): void {
            Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->name('roles.permissions');
            Route::apiResource('roles', RoleController::class);

            Route::patch('users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
            Route::put('users/{user}/role', [UserController::class, 'changeRole'])->name('users.role');
            Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
            Route::patch('users/{user}/vault-balance', [UserController::class, 'setVaultBalance'])->name('users.vault-balance');
            Route::apiResource('users', UserController::class);
        });
    });
});
