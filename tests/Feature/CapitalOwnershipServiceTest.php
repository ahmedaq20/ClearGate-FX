<?php

use App\Enums\CapitalAccountType;
use App\Enums\CapitalMovementType;
use App\Models\AuditLog;
use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\CapitalAccount;
use App\Models\CapitalBoxAllocation;
use App\Models\CapitalTransaction;
use App\Models\User;
use App\Services\CapitalService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function capitalOwner(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function expectCapitalInvariant(CapitalAccount $account): void
{
    $account->refresh();
    $allocationSum = round((float) $account->boxAllocations()->sum('allocated_balance'), 4);

    expect((float) $account->total_balance)->toBe(round((float) $account->unallocated_balance + (float) $account->allocated_balance, 4))
        ->and((float) $account->allocated_balance)->toBe($allocationSum);
}

test('capital account can be created with initial capital directly into unallocated capital', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);

    $account = $service->createCapitalAccount($owner, [
        'name' => 'شركة أحمد للتحويل',
        'type' => CapitalAccountType::Company->value,
        'currency' => 'USD',
        'initial_balance' => 100000,
        'transaction_date' => '2026-05-01',
        'statement' => 'رأس مال افتتاحي',
    ]);

    expect($account->type)->toBe(CapitalAccountType::Company)
        ->and($account->currency)->toBe('USD')
        ->and((float) $account->total_balance)->toBe(100000.0)
        ->and((float) $account->unallocated_balance)->toBe(100000.0)
        ->and((float) $account->allocated_balance)->toBe(0.0)
        ->and($account->transactions)->toHaveCount(1)
        ->and($account->transactions->first()->type)->toBe(CapitalMovementType::InitialDeposit->value)
        ->and(AuditLog::query()->where('action', 'capital_account.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'capital_movement.created')->count())->toBe(1);

    expectCapitalInvariant($account);
});

test('top up into a box increases ownership and box liquidity exactly once', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 0]);
    $account = $service->createCapitalAccount($owner, [
        'name' => 'Investor A',
        'type' => CapitalAccountType::Investor->value,
        'currency' => 'USD',
    ]);

    $movement = $service->topUp($owner, $account, [
        'amount' => 10000,
        'currency' => 'USD',
        'box_id' => $box->id,
        'transaction_date' => '2026-05-02',
        'reference_number' => 'CAP-1',
        'statement' => 'تغذية مباشرة للصندوق التركي',
    ]);

    expect((float) $account->refresh()->total_balance)->toBe(10000.0)
        ->and((float) $account->unallocated_balance)->toBe(0.0)
        ->and((float) $account->allocated_balance)->toBe(10000.0)
        ->and((float) $box->refresh()->current_balance)->toBe(10000.0)
        ->and((float) CapitalBoxAllocation::query()->where('capital_account_id', $account->id)->value('allocated_balance'))->toBe(10000.0)
        ->and($movement->type)->toBe(CapitalMovementType::TopUp->value)
        ->and($movement->total_balance_after)->toBe('10000.0000')
        ->and(BoxBalanceLog::query()->where('box_id', $box->id)->count())->toBe(1);

    expectCapitalInvariant($account);
});

test('withdrawal from unallocated capital decreases total ownership', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);
    $account = $service->createCapitalAccount($owner, [
        'name' => 'Partner A',
        'type' => CapitalAccountType::Partner->value,
        'currency' => 'USD',
        'initial_balance' => 12000,
    ]);

    $movement = $service->withdrawCapital($owner, $account, [
        'amount' => 2000,
        'currency' => 'USD',
        'statement' => 'سحب شريك',
    ]);

    expect((float) $account->refresh()->total_balance)->toBe(10000.0)
        ->and((float) $account->unallocated_balance)->toBe(10000.0)
        ->and((float) $account->allocated_balance)->toBe(0.0)
        ->and($movement->type)->toBe(CapitalMovementType::Withdrawal->value);

    expectCapitalInvariant($account);
});

test('allocation and deallocation move capital location without changing total ownership', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 0]);
    $account = $service->createCapitalAccount($owner, [
        'name' => 'Company B',
        'type' => CapitalAccountType::Company->value,
        'currency' => 'USD',
        'initial_balance' => 100000,
    ]);

    $allocation = $service->allocateToBox($owner, $account, [
        'amount' => 25000,
        'currency' => 'USD',
        'box_id' => $box->id,
        'statement' => 'تخصيص رأس مال للصندوق',
    ]);

    expect((float) $account->refresh()->total_balance)->toBe(100000.0)
        ->and((float) $account->unallocated_balance)->toBe(75000.0)
        ->and((float) $account->allocated_balance)->toBe(25000.0)
        ->and((float) $box->refresh()->current_balance)->toBe(25000.0)
        ->and($allocation->type)->toBe(CapitalMovementType::Allocation->value);

    $deallocation = $service->deallocateFromBox($owner, $account, [
        'amount' => 10000,
        'currency' => 'USD',
        'box_id' => $box->id,
        'statement' => 'إرجاع جزء من الصندوق إلى رأس مال غير مخصص',
    ]);

    expect((float) $account->refresh()->total_balance)->toBe(100000.0)
        ->and((float) $account->unallocated_balance)->toBe(85000.0)
        ->and((float) $account->allocated_balance)->toBe(15000.0)
        ->and((float) $box->refresh()->current_balance)->toBe(15000.0)
        ->and($deallocation->type)->toBe(CapitalMovementType::Deallocation->value)
        ->and(BoxBalanceLog::query()->where('box_id', $box->id)->count())->toBe(2);

    expectCapitalInvariant($account);
});

test('editing top up and withdrawal reverses the original impact before applying the new amount', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);
    $account = $service->createCapitalAccount($owner, [
        'name' => 'Investor B',
        'type' => CapitalAccountType::Investor->value,
        'currency' => 'USD',
        'initial_balance' => 10000,
    ]);
    $topUp = $service->topUp($owner, $account, [
        'amount' => 5000,
        'currency' => 'USD',
    ]);
    $withdrawal = $service->withdrawCapital($owner, $account, [
        'amount' => 2000,
        'currency' => 'USD',
    ]);

    $service->updateCapitalMovement($owner, $topUp, [
        'amount' => 7000,
        'statement' => 'تصحيح التغذية',
    ]);
    $service->updateCapitalMovement($owner, $withdrawal, [
        'amount' => 3000,
        'statement' => 'تصحيح السحب',
    ]);

    expect((float) $account->refresh()->total_balance)->toBe(14000.0)
        ->and((float) $account->unallocated_balance)->toBe(14000.0)
        ->and(CapitalTransaction::query()->where('type', CapitalMovementType::TopUp->value)->value('amount'))->toBe('7000.0000')
        ->and(CapitalTransaction::query()->where('type', CapitalMovementType::Withdrawal->value)->value('amount'))->toBe('3000.0000')
        ->and(AuditLog::query()->where('action', 'capital_movement.updated')->count())->toBe(2);

    expectCapitalInvariant($account);
});

test('deleting a capital movement soft deletes it and reverses ownership and box effects', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 0]);
    $account = $service->createCapitalAccount($owner, [
        'name' => 'Investor C',
        'type' => CapitalAccountType::Investor->value,
        'currency' => 'USD',
        'initial_balance' => 10000,
    ]);
    $allocation = $service->allocateToBox($owner, $account, [
        'amount' => 3000,
        'currency' => 'USD',
        'box_id' => $box->id,
    ]);

    $service->deleteCapitalMovement($owner, $allocation);

    expect((float) $account->refresh()->total_balance)->toBe(10000.0)
        ->and((float) $account->unallocated_balance)->toBe(10000.0)
        ->and((float) $account->allocated_balance)->toBe(0.0)
        ->and((float) $box->refresh()->current_balance)->toBe(0.0)
        ->and(CapitalTransaction::withTrashed()->whereKey($allocation->id)->first()?->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'capital_movement.deleted')->count())->toBe(1);

    expectCapitalInvariant($account);
});

test('multi currency balances remain isolated and currency mismatches are rejected', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);
    $usdBox = Box::factory()->create(['currency' => 'USD', 'current_balance' => 0]);
    $tryBox = Box::factory()->create(['currency' => 'TRY', 'current_balance' => 0]);
    $usdAccount = $service->createCapitalAccount($owner, [
        'name' => 'USD Company',
        'type' => CapitalAccountType::Company->value,
        'currency' => 'USD',
        'initial_balance' => 1000,
    ]);
    $tryAccount = $service->createCapitalAccount($owner, [
        'name' => 'TRY Company',
        'type' => CapitalAccountType::Company->value,
        'currency' => 'TRY',
        'initial_balance' => 3000,
    ]);

    $service->allocateToBox($owner, $usdAccount, [
        'amount' => 500,
        'currency' => 'USD',
        'box_id' => $usdBox->id,
    ]);

    expect(fn () => $service->allocateToBox($owner, $tryAccount, [
        'amount' => 500,
        'currency' => 'TRY',
        'box_id' => $usdBox->id,
    ]))->toThrow(ValidationException::class);

    expect((float) $usdAccount->refresh()->total_balance)->toBe(1000.0)
        ->and((float) $tryAccount->refresh()->total_balance)->toBe(3000.0)
        ->and((float) $tryBox->refresh()->current_balance)->toBe(0.0);
});

test('insufficient balances are rejected for withdrawals allocations and deallocations', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 0]);
    $account = $service->createCapitalAccount($owner, [
        'name' => 'Company C',
        'type' => CapitalAccountType::Company->value,
        'currency' => 'USD',
        'initial_balance' => 1000,
    ]);

    expect(fn () => $service->withdrawCapital($owner, $account, [
        'amount' => 1500,
        'currency' => 'USD',
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->allocateToBox($owner, $account, [
        'amount' => 1500,
        'currency' => 'USD',
        'box_id' => $box->id,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->deallocateFromBox($owner, $account, [
        'amount' => 1,
        'currency' => 'USD',
        'box_id' => $box->id,
    ]))->toThrow(ValidationException::class);
});

test('capital management authorization uses roles and permissions', function (): void {
    $manager = capitalOwner('manager');
    $service = app(CapitalService::class);

    expect(fn () => $service->createCapitalAccount($manager, [
        'name' => 'Unauthorized Company',
        'type' => CapitalAccountType::Company->value,
        'currency' => 'USD',
    ]))->toThrow(AuthorizationException::class);

    $manager->givePermissionTo('capital.account.create');
    $manager->givePermissionTo('capital.movement.create');
    $account = $service->createCapitalAccount($manager, [
        'name' => 'Authorized Company',
        'type' => CapitalAccountType::Company->value,
        'currency' => 'USD',
        'initial_balance' => 100,
    ]);

    expect($account)->toBeInstanceOf(CapitalAccount::class);
});

test('statement exposes opening running and closing balances and repeated updates remain consistent', function (): void {
    $owner = capitalOwner();
    $service = app(CapitalService::class);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 0]);
    $account = $service->createCapitalAccount($owner, [
        'name' => 'Statement Company',
        'type' => CapitalAccountType::Company->value,
        'currency' => 'USD',
        'initial_balance' => 10000,
        'transaction_date' => '2026-05-01',
    ]);

    foreach ([1000, 2000, 3000] as $amount) {
        $service->topUp($owner, $account, [
            'amount' => $amount,
            'currency' => 'USD',
            'transaction_date' => '2026-05-02',
        ]);
    }

    $service->allocateToBox($owner, $account, [
        'amount' => 6000,
        'currency' => 'USD',
        'box_id' => $box->id,
        'transaction_date' => '2026-05-03',
    ]);
    $statement = $service->capitalStatement($owner, $account, [
        'date_from' => '2026-05-02',
        'date_to' => '2026-05-31',
        'currency' => 'USD',
    ]);

    expect($statement['opening_balance']['total'])->toBe(10000.0)
        ->and($statement['closing_balance']['total'])->toBe(16000.0)
        ->and($statement['closing_balance']['unallocated'])->toBe(10000.0)
        ->and($statement['closing_balance']['allocated'])->toBe(6000.0)
        ->and($statement['transactions'])->toHaveCount(4)
        ->and((float) $account->refresh()->total_balance)->toBe(16000.0)
        ->and((float) $box->refresh()->current_balance)->toBe(6000.0);

    expectCapitalInvariant($account);
});
