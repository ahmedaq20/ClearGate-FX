<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExchangeRateController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VaultController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('settings/public', [SettingController::class, 'publicSettings'])->name('settings.public');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
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

        Route::get('customers/{customer}/transactions', [CustomerController::class, 'transactions'])->name('customers.transactions');
        Route::get('customers/{customer}/balance', [CustomerController::class, 'balance'])->name('customers.balance');
        Route::apiResource('customers', CustomerController::class);
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

        Route::put('currencies/{code}/rate', [ExchangeRateController::class, 'update'])->name('currencies.rate.update');
        Route::apiResource('currencies', CurrencyController::class);

        Route::post('exchange-rates/bulk-update', [ExchangeRateController::class, 'bulkUpdate'])->name('exchange-rates.bulk-update');
        Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');

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

        Route::middleware('role:owner,sanctum')->group(function (): void {
            Route::patch('users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
            Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
            Route::patch('users/{user}/vault-balance', [UserController::class, 'setVaultBalance'])->name('users.vault-balance');
            Route::apiResource('users', UserController::class);
        });
    });
});
