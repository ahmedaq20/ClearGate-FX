<?php

use App\Enums\BoxBalanceOperationType;
use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationSettlementDirection;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierDirection;
use App\Enums\OperationSupplierFulfillmentStatus;
use App\Enums\OperationSupplierSettlementStatus;
use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsSupplierSettlementUser(string $role = 'manager'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

test('supplier payable can be partially and fully settled with linked box cash movement', function (): void {
    $user = actingAsSupplierSettlementUser();
    $supplier = Customer::factory()->create(['type' => 'supplier', 'balance_usd' => 900]);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
        'supplier_amount' => 1000,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
        'status' => OperationStatus::Pending->value,
    ]);
    $obligation = OperationObligation::factory()->payable()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'amount' => 1000,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'settled_amount' => 0,
        'balance_amount' => 1000,
        'created_by' => $user->id,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 400,
        'box_id' => $box->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'supplier-partial-1',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث تسوية المورد')
        ->assertJsonPath('data.supplier_settlement_status', OperationSupplierSettlementStatus::PartiallySettled->value)
        ->assertJsonPath('data.supplier_settled_at', null)
        ->assertJsonPath('data.status', OperationStatus::Pending->value);

    expect($obligation->refresh()->status)->toBe(OperationObligationStatus::PartiallySettled)
        ->and((float) $obligation->settled_amount)->toBe(400.0)
        ->and((float) $obligation->balance_amount)->toBe(600.0)
        ->and((float) $box->refresh()->current_balance)->toBe(1600.0)
        ->and((float) $supplier->refresh()->balance_usd)->toBe(900.0);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 600,
        'box_id' => $box->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'supplier-final-1',
    ])
        ->assertOk()
        ->assertJsonPath('data.supplier_settlement_status', OperationSupplierSettlementStatus::Settled->value);

    $settlements = OperationSettlement::query()->orderBy('id')->get();
    $balanceLogs = BoxBalanceLog::query()->orderBy('id')->get();

    expect($operation->refresh()->supplier_settlement_status)->toBe(OperationSupplierSettlementStatus::Settled)
        ->and($operation->supplier_settled_at)->not->toBeNull()
        ->and($obligation->refresh()->status)->toBe(OperationObligationStatus::Settled)
        ->and((float) $obligation->settled_amount)->toBe(1000.0)
        ->and((float) $obligation->balance_amount)->toBe(0.0)
        ->and((float) $box->refresh()->current_balance)->toBe(1000.0)
        ->and($settlements)->toHaveCount(2)
        ->and($settlements[0]->direction)->toBe(OperationSettlementDirection::CashOut)
        ->and($settlements[1]->direction)->toBe(OperationSettlementDirection::CashOut)
        ->and($balanceLogs)->toHaveCount(2)
        ->and($balanceLogs[0]->operation_type)->toBe(BoxBalanceOperationType::Subtract)
        ->and($balanceLogs[0]->operation_settlement_id)->toBe($settlements[0]->id)
        ->and($balanceLogs[1]->operation_settlement_id)->toBe($settlements[1]->id);
});

test('supplier settlement retries do not double move the selected box', function (): void {
    $user = actingAsSupplierSettlementUser();
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
        'supplier_amount' => 1000,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
    ]);
    $obligation = OperationObligation::factory()->payable()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'amount' => 1000,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'settled_amount' => 0,
        'balance_amount' => 1000,
        'created_by' => $user->id,
    ]);
    $payload = [
        'amount' => 1000,
        'box_id' => $box->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'supplier-retry-1',
    ];

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", $payload)->assertOk();
    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", $payload)->assertOk();
    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 1000,
        'box_id' => $box->id,
        'idempotency_key' => 'supplier-retry-1',
    ])->assertOk();

    expect((float) $box->refresh()->current_balance)->toBe(1000.0)
        ->and(OperationSettlement::query()->count())->toBe(1)
        ->and(BoxBalanceLog::query()->count())->toBe(1)
        ->and($obligation->refresh()->status)->toBe(OperationObligationStatus::Settled)
        ->and($operation->refresh()->supplier_settlement_status)->toBe(OperationSupplierSettlementStatus::Settled);
});

test('supplier settlement idempotency key cannot be replayed against a different box', function (): void {
    $user = actingAsSupplierSettlementUser();
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $otherBox = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
    ]);
    $obligation = OperationObligation::factory()->payable()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'amount' => 1000,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'settled_amount' => 0,
        'balance_amount' => 1000,
        'created_by' => $user->id,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 1000,
        'box_id' => $box->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'supplier-box-bound-key',
    ])->assertOk();

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 1000,
        'box_id' => $otherBox->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'supplier-box-bound-key',
    ])->assertUnprocessable();

    $settlement = OperationSettlement::query()->firstOrFail();

    expect($settlement->box_id)->toBe($box->id)
        ->and((float) $box->refresh()->current_balance)->toBe(1000.0)
        ->and((float) $otherBox->refresh()->current_balance)->toBe(2000.0)
        ->and(BoxBalanceLog::query()->count())->toBe(1);
});

test('supplier receivable settlement records cash in to the box', function (): void {
    $user = actingAsSupplierSettlementUser();
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 300]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
        'supplier_amount' => 500,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
    ]);
    $obligation = OperationObligation::factory()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'counterparty_role' => OperationCounterpartyRole::Supplier->value,
        'type' => OperationObligationType::Receivable->value,
        'reason' => OperationObligationReason::SupplierRefund->value,
        'amount' => 500,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'settled_amount' => 0,
        'balance_amount' => 500,
        'created_by' => $user->id,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 500,
        'box_id' => $box->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'supplier-receivable-1',
    ])->assertOk();

    $settlement = OperationSettlement::query()->firstOrFail();
    $balanceLog = BoxBalanceLog::query()->firstOrFail();

    expect((float) $box->refresh()->current_balance)->toBe(800.0)
        ->and($settlement->direction)->toBe(OperationSettlementDirection::CashIn)
        ->and($balanceLog->operation_type)->toBe(BoxBalanceOperationType::Add)
        ->and($operation->refresh()->supplier_settlement_status)->toBe(OperationSupplierSettlementStatus::Settled);
});

test('supplier pays intermediary workflow opens supplier receivable and settles cash into box', function (): void {
    $user = actingAsSupplierSettlementUser();
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 300]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
        'supplier_amount' => 500,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
        'supplier_direction' => OperationSupplierDirection::SupplierPaysIntermediary->value,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ])->assertOk();

    $obligation = OperationObligation::query()->firstOrFail();

    expect($obligation->counterparty_id)->toBe($supplier->id)
        ->and($obligation->counterparty_role)->toBe(OperationCounterpartyRole::Supplier)
        ->and($obligation->type)->toBe(OperationObligationType::Receivable)
        ->and($obligation->reason)->toBe(OperationObligationReason::SupplierPrincipal);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 500,
        'box_id' => $box->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'supplier-pays-intermediary-1',
    ])->assertOk();

    $settlement = OperationSettlement::query()->firstOrFail();
    $balanceLog = BoxBalanceLog::query()->firstOrFail();

    expect((float) $box->refresh()->current_balance)->toBe(800.0)
        ->and($settlement->direction)->toBe(OperationSettlementDirection::CashIn)
        ->and($balanceLog->operation_type)->toBe(BoxBalanceOperationType::Add)
        ->and($operation->refresh()->supplier_settlement_status)->toBe(OperationSupplierSettlementStatus::Settled);
});

test('supplier settlement rejects missing obligations currency mismatches and overdrafts', function (): void {
    $user = actingAsSupplierSettlementUser();
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $usdBox = Box::factory()->create(['currency' => 'USD', 'current_balance' => 100]);
    $egpBox = Box::factory()->create(['currency' => 'EGP', 'current_balance' => 10000]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
        'supplier_amount' => 1000,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
    ]);
    $obligation = OperationObligation::factory()->payable()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'amount' => 1000,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'settled_amount' => 0,
        'balance_amount' => 1000,
        'created_by' => $user->id,
    ]);
    $operationWithoutObligation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
    ]);

    $this->postJson("/api/v1/operations/{$operationWithoutObligation->id}/supplier-settlement", [
        'amount' => 100,
        'box_id' => $usdBox->id,
    ])->assertUnprocessable();

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 100,
        'box_id' => $egpBox->id,
        'operation_obligation_id' => $obligation->id,
    ])->assertUnprocessable();

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 1000,
        'box_id' => $usdBox->id,
        'operation_obligation_id' => $obligation->id,
    ])->assertUnprocessable();

    expect((float) $usdBox->refresh()->current_balance)->toBe(100.0)
        ->and((float) $egpBox->refresh()->current_balance)->toBe(10000.0)
        ->and(OperationSettlement::query()->count())->toBe(0)
        ->and(BoxBalanceLog::query()->count())->toBe(0)
        ->and($operation->refresh()->supplier_settlement_status)->toBeNull();
});

test('supplier settlement rejects cancelled operations and operations without suppliers', function (): void {
    $user = actingAsSupplierSettlementUser();
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $cancelledOperation = Operation::factory()->cancelled()->create(['created_by' => $user->id]);
    $boxFundedOperation = Operation::factory()->boxFunded()->create(['created_by' => $user->id]);
    $payload = [
        'amount' => 100,
        'box_id' => $box->id,
    ];

    $this->postJson("/api/v1/operations/{$cancelledOperation->id}/supplier-settlement", $payload)
        ->assertUnprocessable();

    $this->postJson("/api/v1/operations/{$boxFundedOperation->id}/supplier-settlement", $payload)
        ->assertUnprocessable();

    expect(OperationSettlement::query()->count())->toBe(0)
        ->and(BoxBalanceLog::query()->count())->toBe(0);
});

test('supplier settlement is limited to accessible operations', function (): void {
    $owner = actingAsSupplierSettlementUser('owner');
    $otherUser = User::factory()->create(['is_active' => true]);
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->create([
        'created_by' => $otherUser->id,
        'supplier_id' => $supplier->id,
    ]);
    $obligation = OperationObligation::factory()->payable()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'amount' => 100,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'settled_amount' => 0,
        'balance_amount' => 100,
        'created_by' => $otherUser->id,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 100,
        'box_id' => $box->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'owner-supplier-settlement',
    ])->assertOk();

    $manager = actingAsSupplierSettlementUser();
    $anotherOperation = Operation::factory()->create([
        'created_by' => $otherUser->id,
        'supplier_id' => $supplier->id,
    ]);

    expect($manager->id)->not->toBe($anotherOperation->created_by)
        ->and($owner->isOwner())->toBeTrue();

    $this->postJson("/api/v1/operations/{$anotherOperation->id}/supplier-settlement", [
        'amount' => 100,
        'box_id' => $box->id,
    ])->assertForbidden();
});

test('supplier settlement is limited to boxes the current user can use', function (): void {
    $employee = actingAsSupplierSettlementUser('operations_employee');
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $assignedBox = Box::factory()->create([
        'currency' => 'USD',
        'current_balance' => 2000,
        'assigned_user_id' => $employee->id,
    ]);
    $otherBox = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->create([
        'created_by' => $employee->id,
        'supplier_id' => $supplier->id,
    ]);
    $obligation = OperationObligation::factory()->payable()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'amount' => 1000,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'settled_amount' => 0,
        'balance_amount' => 1000,
        'created_by' => $employee->id,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 100,
        'box_id' => $otherBox->id,
        'operation_obligation_id' => $obligation->id,
    ])->assertUnprocessable();

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-settlement", [
        'amount' => 100,
        'box_id' => $assignedBox->id,
        'operation_obligation_id' => $obligation->id,
        'idempotency_key' => 'employee-assigned-box',
    ])->assertOk();

    expect((float) $assignedBox->refresh()->current_balance)->toBe(1900.0)
        ->and((float) $otherBox->refresh()->current_balance)->toBe(2000.0)
        ->and(BoxBalanceLog::query()->count())->toBe(1);
});
