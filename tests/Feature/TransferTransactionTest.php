<?php

use App\Models\Currency;
use App\Models\Transaction;
use Database\Seeders\RolePermissionSeeder;

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

function transferPayload(int $fromId, int $toId, array $overrides = []): array
{
    return array_merge([
        'type' => 'transfer',
        'from_customer_id' => $fromId,
        'to_customer_id' => $toId,
        'amount' => 100,
        'currency_code' => 'USD',
        'exchange_rate' => 1,
        'transaction_date' => now()->toDateString(),
    ], $overrides);
}

test('transfer between two valid customers succeeds', function (): void {
    $manager = actingAsUserWithRole();
    $fromCustomer = createCustomerFor($manager, 500);
    $toCustomer = createCustomerFor($manager, 200);

    $vaultBefore = (float) $manager->vault->refresh()->balance_usd;

    $this->postJson('/api/v1/transactions', transferPayload($fromCustomer->id, $toCustomer->id))
        ->assertCreated()
        ->assertJsonPath('message', 'تم تنفيذ التحويل بنجاح');

    expect((float) $fromCustomer->refresh()->balance_usd)->toBe(400.0)
        ->and((float) $toCustomer->refresh()->balance_usd)->toBe(300.0)
        ->and((float) $manager->vault->refresh()->balance_usd)->toBe($vaultBefore);
});

test('transfer to same customer fails', function (): void {
    $manager = actingAsUserWithRole();
    $customer = createCustomerFor($manager, 500);

    $this->postJson('/api/v1/transactions', transferPayload($customer->id, $customer->id))
        ->assertStatus(422)
        ->assertJsonPath('errors.to_customer_id.0', 'لا يمكن التحويل إلى نفس العميل');
});

test('transfer with invalid from customer fails', function (): void {
    $manager = actingAsUserWithRole();
    $toCustomer = createCustomerFor($manager, 200);

    $this->postJson('/api/v1/transactions', transferPayload(999999, $toCustomer->id))
        ->assertStatus(422)
        ->assertJsonPath('errors.from_customer_id.0', 'العميل المُحوَّل منه غير موجود');
});

test('transfer with invalid to customer fails', function (): void {
    $manager = actingAsUserWithRole();
    $fromCustomer = createCustomerFor($manager, 500);

    $this->postJson('/api/v1/transactions', transferPayload($fromCustomer->id, 999999))
        ->assertStatus(422)
        ->assertJsonPath('errors.to_customer_id.0', 'العميل المُحوَّل إليه غير موجود');
});

test('manager cannot transfer between customers belonging to another user', function (): void {
    $managerA = actingAsUserWithRole();
    $fromCustomer = createCustomerFor($managerA, 500);
    $toCustomer = createCustomerFor($managerA, 200);

    actingAsUserWithRole(); // managerB

    $this->postJson('/api/v1/transactions', transferPayload($fromCustomer->id, $toCustomer->id))
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

test('sender balance decreases by usd value', function (): void {
    $manager = actingAsUserWithRole();
    $fromCustomer = createCustomerFor($manager, 300);
    $toCustomer = createCustomerFor($manager, 0);

    $this->postJson('/api/v1/transactions', transferPayload($fromCustomer->id, $toCustomer->id, [
        'amount' => 150,
        'exchange_rate' => 1,
    ]))->assertCreated();

    expect((float) $fromCustomer->refresh()->balance_usd)->toBe(150.0);
});

test('receiver balance increases by usd value', function (): void {
    $manager = actingAsUserWithRole();
    $fromCustomer = createCustomerFor($manager, 300);
    $toCustomer = createCustomerFor($manager, 50);

    $this->postJson('/api/v1/transactions', transferPayload($fromCustomer->id, $toCustomer->id, [
        'amount' => 150,
        'exchange_rate' => 1,
    ]))->assertCreated();

    expect((float) $toCustomer->refresh()->balance_usd)->toBe(200.0);
});

test('vault balance remains unchanged after transfer', function (): void {
    $manager = actingAsUserWithRole(initialBalance: 1000);
    $fromCustomer = createCustomerFor($manager, 500);
    $toCustomer = createCustomerFor($manager, 200);

    $vaultBefore = (float) $manager->vault->refresh()->balance_usd;

    $this->postJson('/api/v1/transactions', transferPayload($fromCustomer->id, $toCustomer->id))
        ->assertCreated();

    expect((float) $manager->vault->refresh()->balance_usd)->toBe($vaultBefore);
});

test('owner can transfer between any customers', function (): void {
    $managerA = actingAsUserWithRole();
    $fromCustomer = createCustomerFor($managerA, 500);

    $managerB = actingAsUserWithRole();
    $toCustomer = createCustomerFor($managerB, 200);

    actingAsUserWithRole('owner');

    $this->postJson('/api/v1/transactions', transferPayload($fromCustomer->id, $toCustomer->id))
        ->assertCreated()
        ->assertJsonPath('message', 'تم تنفيذ التحويل بنجاح');

    expect((float) $fromCustomer->refresh()->balance_usd)->toBe(400.0)
        ->and((float) $toCustomer->refresh()->balance_usd)->toBe(300.0);
});

test('transfer stores from and to customer ids on transaction', function (): void {
    $manager = actingAsUserWithRole();
    $fromCustomer = createCustomerFor($manager, 500);
    $toCustomer = createCustomerFor($manager, 200);

    $this->postJson('/api/v1/transactions', transferPayload($fromCustomer->id, $toCustomer->id))
        ->assertCreated();

    $transaction = Transaction::query()->where('type', 'transfer')->firstOrFail();

    expect($transaction->from_customer_id)->toBe($fromCustomer->id)
        ->and($transaction->to_customer_id)->toBe($toCustomer->id);
});
