<?php

use App\Enums\BoxBalanceOperationType;
use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationCustomerDirection;
use App\Enums\OperationCustomerSettlementStatus;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationSettlementDirection;
use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsCustomerSettlementUser(string $role = 'manager'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

test('customer settlement can be marked pending as a receivable without moving cash', function (): void {
    $user = actingAsCustomerSettlementUser();
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Pending->value,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث تسوية العميل')
        ->assertJsonPath('data.customer_direction', OperationCustomerDirection::CustomerPaysIntermediary->value)
        ->assertJsonPath('data.customer_settlement_status', OperationCustomerSettlementStatus::Pending->value)
        ->assertJsonPath('data.customer_settled_at', null);

    $obligation = OperationObligation::query()->firstOrFail();

    expect($obligation->operation_id)->toBe($operation->id)
        ->and($obligation->counterparty_id)->toBe($operation->customer_id)
        ->and($obligation->counterparty_role)->toBe(OperationCounterpartyRole::Customer)
        ->and($obligation->type)->toBe(OperationObligationType::Receivable)
        ->and($obligation->reason)->toBe(OperationObligationReason::CustomerPrincipal)
        ->and($obligation->status)->toBe(OperationObligationStatus::Open)
        ->and((float) $obligation->balance_amount)->toBe(1000.0)
        ->and(OperationSettlement::query()->count())->toBe(0)
        ->and(BoxBalanceLog::query()->count())->toBe(0);
});

test('customer cash in settles the receivable and increments the selected box once', function (): void {
    $user = actingAsCustomerSettlementUser();
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 500]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Pending->value,
    ])->assertOk();

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $box->id,
        'idempotency_key' => 'customer-cash-in-1',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer_settlement_status', OperationCustomerSettlementStatus::Completed->value);

    $obligation = OperationObligation::query()->firstOrFail();
    $settlement = OperationSettlement::query()->firstOrFail();
    $balanceLog = BoxBalanceLog::query()->firstOrFail();

    expect($operation->refresh()->customer_settlement_status)->toBe(OperationCustomerSettlementStatus::Completed)
        ->and($operation->customer_settled_at)->not->toBeNull()
        ->and($obligation->refresh()->status)->toBe(OperationObligationStatus::Settled)
        ->and((float) $obligation->settled_amount)->toBe(1000.0)
        ->and((float) $box->refresh()->current_balance)->toBe(1500.0)
        ->and($settlement->operation_obligation_id)->toBe($obligation->id)
        ->and($settlement->direction)->toBe(OperationSettlementDirection::CashIn)
        ->and($settlement->box_id)->toBe($box->id)
        ->and($balanceLog->operation_settlement_id)->toBe($settlement->id)
        ->and($balanceLog->operation_type)->toBe(BoxBalanceOperationType::Add);
});

test('customer cash out creates a payable settlement and rejects overdrafts', function (): void {
    $user = actingAsCustomerSettlementUser();
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 1200]);
    $smallBox = Box::factory()->create(['currency' => 'USD', 'current_balance' => 100]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
    ]);
    $overdraftOperation = Operation::factory()->create([
        'created_by' => $user->id,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
    ]);

    $this->postJson("/api/v1/operations/{$overdraftOperation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::IntermediaryPaysCustomer->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $smallBox->id,
    ])->assertUnprocessable();

    expect((float) $smallBox->refresh()->current_balance)->toBe(100.0)
        ->and(OperationSettlement::query()->count())->toBe(0)
        ->and(BoxBalanceLog::query()->count())->toBe(0);

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::IntermediaryPaysCustomer->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $box->id,
        'idempotency_key' => 'customer-cash-out-1',
    ])->assertOk();

    $settlement = OperationSettlement::query()->firstOrFail();
    $balanceLog = BoxBalanceLog::query()->firstOrFail();

    expect($operation->refresh()->customer_direction)->toBe(OperationCustomerDirection::IntermediaryPaysCustomer)
        ->and($operation->customer_settlement_status)->toBe(OperationCustomerSettlementStatus::Completed)
        ->and((float) $box->refresh()->current_balance)->toBe(200.0)
        ->and(OperationObligation::query()->count())->toBe(0)
        ->and($settlement->direction)->toBe(OperationSettlementDirection::CashOut)
        ->and($settlement->operation_obligation_id)->toBeNull()
        ->and($balanceLog->operation_type)->toBe(BoxBalanceOperationType::Subtract)
        ->and($balanceLog->operation_settlement_id)->toBe($settlement->id);
});

test('customer completed settlement can be retried with the same idempotency key without double moving the box', function (): void {
    $user = actingAsCustomerSettlementUser();
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 500]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
    ]);
    $payload = [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $box->id,
        'idempotency_key' => 'retry-customer-settlement',
    ];

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", $payload)->assertOk();
    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", $payload)->assertOk();

    expect((float) $box->refresh()->current_balance)->toBe(1500.0)
        ->and(OperationSettlement::query()->count())->toBe(1)
        ->and(BoxBalanceLog::query()->count())->toBe(1);
});

test('customer settlement is limited to accessible operations', function (): void {
    $owner = actingAsCustomerSettlementUser('owner');
    $otherUser = User::factory()->create(['is_active' => true]);
    $operation = Operation::factory()->create(['created_by' => $otherUser->id]);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $payload = [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $box->id,
        'idempotency_key' => 'owner-customer-settlement',
    ];

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", $payload)->assertOk();

    $manager = actingAsCustomerSettlementUser();

    expect($manager->id)->not->toBe($operation->created_by)
        ->and($owner->isOwner())->toBeTrue();

    $anotherOperation = Operation::factory()->create(['created_by' => $otherUser->id]);

    $this->postJson("/api/v1/operations/{$anotherOperation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Pending->value,
    ])->assertForbidden();
});
