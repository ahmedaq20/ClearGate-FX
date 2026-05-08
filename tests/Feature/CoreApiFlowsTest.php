<?php

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

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

test('restore non deleted transaction returns arabic validation message', function (): void {
    $owner = actingAsUserWithRole('owner', 1000);

    $this->postJson('/api/v1/transactions', transactionPayload())->assertCreated();
    $transaction = Transaction::query()->where('user_id', $owner->id)->firstOrFail();

    $this->patchJson("/api/v1/transactions/{$transaction->id}/restore")
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'لا يمكن استعادة عملية غير محذوفة');
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
