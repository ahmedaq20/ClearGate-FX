<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\VaultService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'transaction.viewAny',
            'transaction.view',
            'transaction.create',
            'transaction.update',
            'transaction.delete',
            'transaction.restore',
            'transaction.forceDelete',
            'transaction.export',
            'customer.viewAny',
            'customer.view',
            'customer.create',
            'customer.update',
            'customer.delete',
            'customer.restore',
            'customer.forceDelete',
            'customer.viewBalance',
            'customer.viewStatement',
            'box.viewAny',
            'box.view',
            'box.create',
            'box.update',
            'box.delete',
            'box.adjustBalance',
            'box.viewLogs',
            'box.adjustment.view',
            'box.adjustment.create',
            'vault.viewAny',
            'vault.view',
            'vault.update',
            'currency.viewAny',
            'currency.manage',
            'exchange_rate.update',
            'dashboard.viewFinancial',
            'reconciliation.view',
            'reconciliation.run',
            'report.daily',
            'report.monthly',
            'report.export',
            'report.viewAll',
            'user.viewAny',
            'user.view',
            'user.create',
            'user.update',
            'user.delete',
            'user.setVaultBalance',
            'settings.view',
            'settings.manage',
            'archive.view',
            'archive.restore',
            'notification.viewAny',
            'notification.read',
            'notification.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
            ]);
        }

        $owner = Role::query()->firstOrCreate([
            'name' => 'owner',
            'guard_name' => 'sanctum',
        ]);
        $owner->syncPermissions($permissions);

        $admin = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'sanctum',
        ]);
        $admin->syncPermissions($permissions);

        $manager = Role::query()->firstOrCreate([
            'name' => 'manager',
            'guard_name' => 'sanctum',
        ]);
        $manager->syncPermissions([
            'transaction.viewAny',
            'transaction.view',
            'transaction.create',
            'transaction.update',
            'transaction.delete',
            'transaction.export',
            'customer.viewAny',
            'customer.view',
            'customer.create',
            'customer.update',
            'customer.delete',
            'customer.viewBalance',
            'customer.viewStatement',
            'box.viewAny',
            'box.view',
            'box.create',
            'box.update',
            'box.delete',
            'box.adjustBalance',
            'box.viewLogs',
            'box.adjustment.view',
            'vault.viewAny',
            'vault.view',
            'vault.update',
            'currency.viewAny',
            'currency.manage',
            'exchange_rate.update',
            'dashboard.viewFinancial',
            'reconciliation.view',
            'report.daily',
            'report.monthly',
            'report.export',
            'settings.view',
            'archive.view',
            'notification.viewAny',
            'notification.read',
            'notification.delete',
        ]);

        $operationsEmployee = Role::query()->firstOrCreate([
            'name' => 'operations_employee',
            'guard_name' => 'sanctum',
        ]);
        $operationsEmployee->syncPermissions([]);

        $user = User::query()->firstOrCreate(
            ['email' => 'owner@exchange.com'],
            [
                'name' => 'المالك',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $user->assignRole($owner);
        app(VaultService::class)->createForUser($user, (float) $user->initial_balance);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
