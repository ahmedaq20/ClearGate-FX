<?php

use App\Models\Archive;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    Currency::query()->updateOrCreate(
        ['code' => 'USD'],
        [
            'name' => 'US Dollar',
            'name_ar' => 'دولار أمريكي',
            'symbol' => '$',
            'rate_to_usd' => 1,
            'is_active' => true,
        ]
    );
});

function actingAsUserWithRole(string $role = 'manager', float $initialBalance = 1000): User
{
    $user = User::factory()->create([
        'initial_balance' => $initialBalance,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user->refresh()->load('vault');
}

function createCustomerFor(User $user, float $balance = 0): Customer
{
    return Customer::factory()->create([
        'user_id' => $user->id,
        'vault_id' => $user->vault->id,
        'balance_usd' => $balance,
    ]);
}

function transactionPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'receive',
        'amount' => 200,
        'currency_code' => 'USD',
        'exchange_rate' => 2,
        'commission_type' => 'percentage',
        'commission_rate' => 10,
        'commission_sign' => 1,
        'transaction_date' => now()->toDateString(),
    ], $overrides);
}

test('login returns token', function (): void {
    $user = User::factory()->create([
        'email' => 'manager@example.test',
        'password' => Hash::make('secret-password'),
        'is_active' => true,
    ]);
    $user->assignRole('manager');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'manager@example.test',
        'password' => 'secret-password',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'تم تسجيل الدخول')
        ->assertJsonStructure(['data' => ['token', 'user']]);
});

test('change password validates the current password before updating', function (): void {
    $user = actingAsUserWithRole();
    $user->update(['password' => Hash::make('old-password')]);

    $this->putJson('/api/v1/auth/change-password', [
        'current_password' => 'new-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'كلمة المرور الحالية غير صحيحة');

    expect(Hash::check('old-password', $user->refresh()->password))->toBeTrue();

    $this->putJson('/api/v1/auth/change-password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تغيير كلمة المرور');

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('manager cannot access another managers customer', function (): void {
    $owner = actingAsUserWithRole();
    $customer = createCustomerFor($owner);
    actingAsUserWithRole();

    $this->getJson("/api/v1/customers/{$customer->id}/balance")
        ->assertForbidden()
        ->assertJsonPath('message', 'غير مصرح');
});

test('customer creation requires user vault', function (): void {
    $manager = actingAsUserWithRole();
    $manager->vault->forceDelete();

    $this->postJson('/api/v1/customers', [
        'name' => 'No Vault Customer',
    ])
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'صندوق المستخدم غير موجود. يرجى التواصل مع المسؤول.');
});

test('transaction create calculates financial fields and updates vault and customer balances', function (): void {
    $manager = actingAsUserWithRole(initialBalance: 1000);
    $customer = createCustomerFor($manager);

    $this->postJson('/api/v1/transactions', transactionPayload([
        'customer_id' => $customer->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('message', 'تم إنشاء العملية');

    $transaction = Transaction::query()->firstOrFail();

    expect((float) $transaction->usd_value)->toBe(100.0)
        ->and((float) $transaction->commission_usd)->toBe(10.0)
        ->and((float) $transaction->net_usd_value)->toBe(110.0)
        ->and($transaction->direction)->toBe(1)
        ->and((float) $manager->vault->refresh()->balance_usd)->toBe(1110.0)
        ->and((float) $customer->refresh()->balance_usd)->toBe(110.0);
});

test('send transaction updates vault balance using net usd value times direction', function (): void {
    $manager = actingAsUserWithRole(initialBalance: 1000);

    $this->postJson('/api/v1/transactions', transactionPayload([
        'type' => 'send',
        'commission_type' => 'fixed',
        'commission_rate' => 5,
        'commission_sign' => -1,
    ]))
        ->assertCreated();

    $transaction = Transaction::query()->firstOrFail();

    expect((float) $transaction->usd_value)->toBe(100.0)
        ->and((float) $transaction->commission_usd)->toBe(5.0)
        ->and((float) $transaction->net_usd_value)->toBe(95.0)
        ->and($transaction->direction)->toBe(-1)
        ->and((float) $manager->vault->refresh()->balance_usd)->toBe(905.0);
});

test('soft delete reverses balance and restore reapplies balance', function (): void {
    $owner = actingAsUserWithRole('owner', 1000);
    $customer = createCustomerFor($owner);

    $this->postJson('/api/v1/transactions', transactionPayload([
        'customer_id' => $customer->id,
    ]))->assertCreated();

    $transaction = Transaction::query()->firstOrFail();

    $this->deleteJson("/api/v1/transactions/{$transaction->id}")
        ->assertOk()
        ->assertJsonPath('message', 'تم حذف العملية');

    expect((float) $owner->vault->refresh()->balance_usd)->toBe(1000.0)
        ->and((float) $customer->refresh()->balance_usd)->toBe(0.0);

    $this->patchJson("/api/v1/transactions/{$transaction->id}/restore")
        ->assertOk()
        ->assertJsonPath('message', 'تم استعادة العملية');

    expect((float) $owner->vault->refresh()->balance_usd)->toBe(1110.0)
        ->and((float) $customer->refresh()->balance_usd)->toBe(110.0);
});

test('archive restore cannot reapply an already restored transaction balance', function (): void {
    $owner = actingAsUserWithRole('owner', 1000);
    $customer = createCustomerFor($owner);

    $this->postJson('/api/v1/transactions', transactionPayload([
        'customer_id' => $customer->id,
    ]))->assertCreated();

    $transaction = Transaction::query()->firstOrFail();

    $this->deleteJson("/api/v1/transactions/{$transaction->id}")->assertOk();

    $archive = Archive::query()
        ->where('archivable_type', 'transaction')
        ->where('archivable_id', $transaction->id)
        ->firstOrFail();

    $this->postJson("/api/v1/archive/{$archive->id}/restore")
        ->assertOk()
        ->assertJsonPath('message', 'تمت الاستعادة من الأرشيف');

    expect((float) $owner->vault->refresh()->balance_usd)->toBe(1110.0)
        ->and((float) $customer->refresh()->balance_usd)->toBe(110.0);

    $this->postJson("/api/v1/archive/{$archive->id}/restore")
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن استعادة عملية غير محذوفة');

    expect((float) $owner->vault->refresh()->balance_usd)->toBe(1110.0)
        ->and((float) $customer->refresh()->balance_usd)->toBe(110.0);
});

test('restore non deleted transaction returns arabic validation message', function (): void {
    $owner = actingAsUserWithRole('owner', 1000);

    $this->postJson('/api/v1/transactions', transactionPayload())->assertCreated();
    $transaction = Transaction::query()->where('user_id', $owner->id)->firstOrFail();

    $this->patchJson("/api/v1/transactions/{$transaction->id}/restore")
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'لا يمكن استعادة عملية غير محذوفة');
});

test('force deleting an active transaction is rejected to preserve balances', function (): void {
    $owner = actingAsUserWithRole('owner', 1000);

    $this->postJson('/api/v1/transactions', transactionPayload())->assertCreated();
    $transaction = Transaction::query()->where('user_id', $owner->id)->firstOrFail();

    $this->deleteJson("/api/v1/transactions/{$transaction->id}/force")
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن حذف عملية نهائياً قبل حذفها مؤقتاً');

    expect((float) $owner->vault->refresh()->balance_usd)->toBe(1110.0)
        ->and(Transaction::query()->whereKey($transaction->id)->exists())->toBeTrue();
});

test('force deleting a customer with transactions is rejected with arabic conflict message', function (): void {
    $owner = actingAsUserWithRole('owner', 1000);
    $customer = createCustomerFor($owner);

    $this->postJson('/api/v1/transactions', transactionPayload([
        'customer_id' => $customer->id,
    ]))->assertCreated();

    $this->deleteJson("/api/v1/customers/{$customer->id}")->assertOk();

    $this->deleteJson("/api/v1/customers/{$customer->id}/force")
        ->assertStatus(409)
        ->assertJsonPath('message', 'لا يمكن حذف عميل نهائياً لوجود عمليات مرتبطة به');
});

test('invalid customer id returns arabic validation error', function (): void {
    actingAsUserWithRole();

    $this->postJson('/api/v1/transactions', transactionPayload([
        'customer_id' => 999999,
    ]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'العميل المحدد غير موجود')
        ->assertJsonPath('errors.customer_id.0', 'العميل المحدد غير موجود');
});

test('unauthorized customer id returns arabic forbidden message', function (): void {
    $manager = actingAsUserWithRole();
    $customer = createCustomerFor($manager);
    actingAsUserWithRole();

    $this->postJson('/api/v1/transactions', transactionPayload([
        'customer_id' => $customer->id,
    ]))
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'لا يمكنك تنفيذ عملية لهذا العميل لأنه غير تابع لحسابك');
});

test('owner can list permissions with arabic labels and groups', function (): void {
    actingAsUserWithRole('owner');

    $this->getJson('/api/v1/permissions')
        ->assertOk()
        ->assertJsonFragment([
            'name' => 'transaction.create',
            'label' => 'إنشاء عملية مالية',
            'group' => 'transactions',
            'group_label' => 'العمليات المالية',
        ]);
});

test('manager cannot access role management endpoints', function (): void {
    actingAsUserWithRole('manager');

    $this->getJson('/api/v1/roles')
        ->assertForbidden();
});

test('owner can create custom role sync permissions and assign it to a user', function (): void {
    $owner = actingAsUserWithRole('owner');

    $this->postJson('/api/v1/roles', [
        'name' => 'auditor',
        'permissions' => ['transaction.viewAny', 'customer.viewAny'],
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'تم إنشاء الدور')
        ->assertJsonPath('data.name', 'auditor')
        ->assertJsonPath('data.label', 'auditor')
        ->assertJsonFragment([
            'name' => 'transaction.viewAny',
            'label' => 'عرض كل العمليات المالية',
            'group' => 'transactions',
            'group_label' => 'العمليات المالية',
        ]);

    $role = Role::query()->where('name', 'auditor')->firstOrFail();

    $this->putJson("/api/v1/roles/{$role->id}/permissions", [
        'permissions' => ['report.daily', 'report.monthly'],
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث صلاحيات الدور')
        ->assertJsonFragment([
            'name' => 'report.daily',
            'label' => 'عرض التقرير اليومي',
            'group' => 'reports',
            'group_label' => 'التقارير',
        ]);

    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('manager');

    $this->putJson("/api/v1/users/{$user->id}/role", [
        'role' => 'auditor',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث دور المستخدم')
        ->assertJsonPath('data.roles.0.name', 'auditor')
        ->assertJsonPath('data.roles.0.label', 'auditor');

    expect($user->refresh()->hasRole('auditor', 'sanctum'))->toBeTrue()
        ->and($owner->refresh()->hasRole('owner', 'sanctum'))->toBeTrue();
});

test('owner can create user with role and receives role labels', function (): void {
    actingAsUserWithRole('owner');

    $this->postJson('/api/v1/users', [
        'name' => 'Role Managed User',
        'email' => 'role-managed@example.test',
        'password' => 'password123',
        'phone' => '+970599111222',
        'role' => 'manager',
        'initial_balance' => 1500,
        'is_active' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'تم إنشاء المستخدم')
        ->assertJsonPath('data.roles.0.name', 'manager')
        ->assertJsonPath('data.roles.0.label', 'مدير')
        ->assertJsonPath('data.vault.initial_balance', '1500.0000');

    $user = User::query()->where('email', 'role-managed@example.test')->firstOrFail();

    expect($user->hasRole('manager', 'sanctum'))->toBeTrue()
        ->and($user->vault()->exists())->toBeTrue();
});

test('system role and last owner protections are enforced', function (): void {
    $owner = User::role('owner', 'sanctum')->firstOrFail();
    Sanctum::actingAs($owner);

    $managerRole = Role::query()->where('name', 'manager')->firstOrFail();
    $ownerRole = Role::query()->where('name', 'owner')->firstOrFail();

    $this->deleteJson("/api/v1/roles/{$ownerRole->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن حذف دور المالك');

    $this->deleteJson("/api/v1/roles/{$managerRole->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن حذف دور المدير الافتراضي');

    $this->putJson("/api/v1/roles/{$ownerRole->id}/permissions", [
        'permissions' => ['transaction.viewAny'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن إزالة الصلاحيات الأساسية من دور المالك');

    $this->putJson("/api/v1/users/{$owner->id}/role", [
        'role' => 'manager',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن تغيير دور آخر مستخدم مالك');

    $this->deleteJson("/api/v1/users/{$owner->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن حذف آخر مستخدم مالك');
});
