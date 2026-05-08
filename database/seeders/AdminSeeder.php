<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\VaultService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ownerRole = Role::query()->firstOrCreate([
            'name' => 'owner',
            'guard_name' => 'sanctum',
        ]);

        $admin = User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@cleargate-fx.com')],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'is_active' => true,
                'initial_balance' => (float) env('ADMIN_INITIAL_BALANCE', 0),
            ]
        );

        $admin->syncRoles([$ownerRole]);

        if ($admin->vault()->doesntExist()) {
            app(VaultService::class)->createForUser($admin, (float) $admin->initial_balance);
        }
    }
}
