<?php

use App\Enums\CapitalMovementType;
use App\Models\CapitalAccount;
use App\Models\CapitalTransaction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsCapitalAccountOwner(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('owner');

    Sanctum::actingAs($user);

    return $user;
}

test('capital account api shows one row per account and summarizes own and investor capital by currency', function (): void {
    actingAsCapitalAccountOwner();

    $this->postJson('/api/v1/capital/accounts', [
        'type' => 'own',
        'amount' => 50000,
        'currency' => 'USD',
        'transaction_date' => '2026-06-01',
        'notes' => 'رأس مالي الخاص',
    ])->assertCreated();

    $this->postJson('/api/v1/capital/accounts', [
        'type' => 'investor',
        'name' => 'محل ذهب',
        'amount' => 10000,
        'currency' => 'USD',
        'transaction_date' => '2026-06-01',
        'notes' => 'استثمار أولي',
    ])->assertCreated();

    $this->postJson('/api/v1/capital/accounts', [
        'type' => 'investor',
        'name' => 'محل ذهب',
        'amount' => 5000,
        'currency' => 'USD',
        'transaction_date' => '2026-06-15',
        'notes' => 'زيادة رأس المال',
    ])->assertCreated();

    expect(CapitalAccount::query()->count())->toBe(2);

    $response = $this->getJson('/api/v1/capital/accounts')
        ->assertOk()
        ->assertJsonPath('data.summaries.0.currency', 'USD')
        ->assertJsonPath('data.summaries.0.own_capital', 50000)
        ->assertJsonPath('data.summaries.0.investor_capital', 15000)
        ->assertJsonPath('data.summaries.0.total_capital', 65000)
        ->assertJsonCount(2, 'data.accounts');

    $investor = collect($response->json('data.accounts'))->firstWhere('name', 'محل ذهب');

    expect($investor['type'])->toBe('investor')
        ->and($investor['current_balance'])->toBe(15000)
        ->and($investor['last_movement_date'])->toContain('2026-06-15');
});

test('capital movement api adds and withdraws money under the same account history', function (): void {
    actingAsCapitalAccountOwner();

    $this->postJson('/api/v1/capital/accounts', [
        'type' => 'investor',
        'name' => 'شركة أحمد',
        'amount' => 10000,
        'currency' => 'USD',
        'transaction_date' => '2026-05-01',
        'notes' => 'استثمار أولي',
    ])->assertCreated();

    $account = CapitalAccount::query()->where('name', 'شركة أحمد')->firstOrFail();

    $this->postJson("/api/v1/capital/accounts/{$account->id}/movements", [
        'type' => 'top_up',
        'amount' => 5000,
        'transaction_date' => '2026-05-15',
        'notes' => 'زيادة رأس المال',
    ])->assertCreated();

    $this->postJson("/api/v1/capital/accounts/{$account->id}/movements", [
        'type' => 'withdrawal',
        'amount' => 3000,
        'transaction_date' => '2026-06-01',
        'notes' => 'سحب جزء من رأس المال',
    ])->assertCreated();

    expect((float) $account->refresh()->total_balance)->toBe(12000.0)
        ->and(CapitalAccount::query()->count())->toBe(1);

    $response = $this->getJson("/api/v1/capital/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('data.account.current_balance', 12000)
        ->assertJsonCount(3, 'data.movements');

    $withdrawal = collect($response->json('data.movements'))->last();

    expect($withdrawal['type'])->toBe(CapitalMovementType::Withdrawal->value)
        ->and($withdrawal['direction'])->toBe('out')
        ->and($withdrawal['amount'])->toBe(-3000)
        ->and($withdrawal['balance_after'])->toBe(12000)
        ->and($withdrawal['notes'])->toBe('سحب جزء من رأس المال');
});

test('capital movement api edits and deletes movements through backend reconciliation', function (): void {
    actingAsCapitalAccountOwner();

    $this->postJson('/api/v1/capital/accounts', [
        'type' => 'investor',
        'name' => 'مستثمر آخر',
        'amount' => 10000,
        'currency' => 'USD',
    ])->assertCreated();

    $account = CapitalAccount::query()->where('name', 'مستثمر آخر')->firstOrFail();

    $this->postJson("/api/v1/capital/accounts/{$account->id}/movements", [
        'type' => 'top_up',
        'amount' => 5000,
        'notes' => 'زيادة رأس المال',
    ])->assertCreated();

    $topUp = CapitalTransaction::query()
        ->where('capital_account_id', $account->id)
        ->where('type', CapitalMovementType::TopUp->value)
        ->firstOrFail();

    $this->patchJson("/api/v1/capital/movements/{$topUp->id}", [
        'amount' => 7000,
        'notes' => 'تصحيح زيادة رأس المال',
    ])
        ->assertOk()
        ->assertJsonPath('data.amount', '7000.0000');

    expect((float) $account->refresh()->total_balance)->toBe(17000.0);

    $this->deleteJson("/api/v1/capital/movements/{$topUp->id}")
        ->assertOk()
        ->assertJsonPath('message', 'تم حذف حركة رأس المال');

    expect((float) $account->refresh()->total_balance)->toBe(10000.0)
        ->and(CapitalTransaction::withTrashed()->whereKey($topUp->id)->first()?->trashed())->toBeTrue();
});
