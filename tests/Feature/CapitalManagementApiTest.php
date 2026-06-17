<?php

use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\CapitalAccount;
use App\Models\CapitalTransaction;
use App\Models\OwnerExpense;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsCapitalUser(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

test('owner can deposit withdraw and transfer capital to a box with movement logs', function (): void {
    $owner = actingAsCapitalUser();
    $box = Box::factory()->create(['current_balance' => 100]);

    $this->postJson('/api/v1/capital/deposit', [
        'amount' => 1000,
        'transaction_date' => '2026-06-17',
        'notes' => 'Initial capital',
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'تم إيداع رأس المال')
        ->assertJsonPath('data.type', 'deposit')
        ->assertJsonPath('data.balance_before', '0.0000')
        ->assertJsonPath('data.balance_after', '1000.0000');

    $this->postJson('/api/v1/capital/withdraw', [
        'amount' => 200,
        'transaction_date' => '2026-06-18',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'withdraw')
        ->assertJsonPath('data.balance_before', '1000.0000')
        ->assertJsonPath('data.balance_after', '800.0000');

    $this->postJson('/api/v1/capital/transfer-to-box', [
        'box_id' => $box->id,
        'amount' => 300,
        'transaction_date' => '2026-06-19',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'box_transfer')
        ->assertJsonPath('data.box_id', $box->id)
        ->assertJsonPath('data.balance_before', '800.0000')
        ->assertJsonPath('data.balance_after', '500.0000');

    expect((float) CapitalAccount::query()->where('user_id', $owner->id)->value('balance_usd'))->toBe(500.0)
        ->and((float) $box->refresh()->current_balance)->toBe(400.0)
        ->and(CapitalTransaction::query()->where('user_id', $owner->id)->count())->toBe(3)
        ->and(BoxBalanceLog::query()->where('box_id', $box->id)->count())->toBe(1);

    $this->getJson('/api/v1/capital')
        ->assertOk()
        ->assertJsonPath('data.capital_balance', 500)
        ->assertJsonPath('data.boxes_total_balance', 400)
        ->assertJsonPath('data.free_capital', 500);

    $this->getJson('/api/v1/capital/transactions')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('capital movements cannot overdraw capital', function (): void {
    actingAsCapitalUser();

    $this->postJson('/api/v1/capital/deposit', ['amount' => 100])
        ->assertCreated();

    $this->postJson('/api/v1/capital/withdraw', ['amount' => 150])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});

test('owner expenses reduce capital and update delete create balancing movement logs', function (): void {
    $owner = actingAsCapitalUser();

    $this->postJson('/api/v1/capital/deposit', ['amount' => 1000])
        ->assertCreated();

    $this->postJson('/api/v1/expenses', [
        'title' => 'School fees',
        'category' => 'education',
        'amount' => 250,
        'expense_date' => '2026-06-10',
        'notes' => 'Term payment',
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'تم إنشاء مصروف المالك')
        ->assertJsonPath('data.title', 'School fees')
        ->assertJsonPath('data.category', 'education');

    $expense = OwnerExpense::query()->firstOrFail();

    expect((float) $owner->capitalAccount()->value('balance_usd'))->toBe(750.0)
        ->and(CapitalTransaction::query()->where('type', 'expense')->count())->toBe(1);

    $this->putJson("/api/v1/expenses/{$expense->id}", [
        'amount' => 300,
        'category' => 'family',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث مصروف المالك')
        ->assertJsonPath('data.category', 'family')
        ->assertJsonPath('data.amount', '300.0000');

    expect((float) $owner->capitalAccount()->value('balance_usd'))->toBe(700.0)
        ->and(CapitalTransaction::query()->where('type', 'expense')->count())->toBe(2);

    $this->deleteJson("/api/v1/expenses/{$expense->id}")
        ->assertOk()
        ->assertJsonPath('message', 'تم حذف مصروف المالك');

    expect((float) $owner->capitalAccount()->value('balance_usd'))->toBe(1000.0)
        ->and(OwnerExpense::query()->count())->toBe(0)
        ->and(CapitalTransaction::query()->where('type', 'expense')->count())->toBe(3);
});

test('capital reports return expenses capital movements and net worth', function (): void {
    actingAsCapitalUser();
    Box::factory()->create(['current_balance' => 400]);

    $this->postJson('/api/v1/capital/deposit', [
        'amount' => 1000,
        'transaction_date' => '2026-06-01',
    ])->assertCreated();

    $this->postJson('/api/v1/expenses', [
        'title' => 'Medical bill',
        'category' => 'medical',
        'amount' => 125,
        'expense_date' => '2026-06-03',
    ])->assertCreated();

    $this->getJson('/api/v1/reports/expense-report?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->assertJsonPath('data.total_expenses', 125)
        ->assertJsonPath('data.expenses_count', 1)
        ->assertJsonPath('data.by_category.0.category', 'medical')
        ->assertJsonPath('data.by_category.0.total_amount', 125);

    $this->getJson('/api/v1/reports/capital-report')
        ->assertOk()
        ->assertJsonPath('data.capital_balance', 875)
        ->assertJsonPath('data.by_type.0.type', 'deposit')
        ->assertJsonPath('data.by_type.1.type', 'expense');

    $this->getJson('/api/v1/reports/net-worth-report')
        ->assertOk()
        ->assertJsonPath('data.capital_balance', 875)
        ->assertJsonPath('data.boxes_total_balance', 400)
        ->assertJsonPath('data.net_worth', 1275);
});

test('capital module is owner only', function (): void {
    actingAsCapitalUser('manager');

    $this->getJson('/api/v1/capital')
        ->assertForbidden()
        ->assertJsonPath('message', 'غير مصرح');

    $this->postJson('/api/v1/capital/deposit', ['amount' => 100])
        ->assertForbidden();

    $this->getJson('/api/v1/expenses')
        ->assertForbidden();
});
