<?php

use App\Enums\OperationCustomerDirection;
use App\Enums\OperationCustomerSettlementStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierFulfillmentStatus;
use App\Enums\OperationSupplierSettlementStatus;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsOperationStateUser(string $role = 'manager'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

test('operation remains pending when only customer settlement is completed', function (): void {
    $user = actingAsOperationStateUser();
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
        'status' => OperationStatus::Pending->value,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $box->id,
        'idempotency_key' => 'state-customer-only',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', OperationStatus::Pending->value)
        ->assertJsonPath('data.customer_settlement_status', OperationCustomerSettlementStatus::Completed->value)
        ->assertJsonPath('data.supplier_fulfillment_status', null);

    expect($operation->refresh()->status)->toBe(OperationStatus::Pending)
        ->and($operation->completed_at)->toBeNull();
});

test('operation remains pending when only supplier fulfillment is completed', function (): void {
    $user = actingAsOperationStateUser();
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_amount' => 1000,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
        'status' => OperationStatus::Pending->value,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', OperationStatus::Pending->value)
        ->assertJsonPath('data.supplier_fulfillment_status', OperationSupplierFulfillmentStatus::Completed->value)
        ->assertJsonPath('data.customer_settlement_status', null);

    expect($operation->refresh()->status)->toBe(OperationStatus::Pending)
        ->and($operation->completed_at)->toBeNull()
        ->and(OperationObligation::query()->count())->toBe(1);
});

test('operation completes when customer settlement and supplier fulfillment are completed while supplier settlement remains independent', function (): void {
    $user = actingAsOperationStateUser();
    $supplier = Customer::factory()->create(['type' => 'supplier', 'balance_usd' => 500]);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
        'supplier_amount' => 1000,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
        'supplier_settlement_status' => OperationSupplierSettlementStatus::Unsettled->value,
        'status' => OperationStatus::Pending->value,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $box->id,
        'idempotency_key' => 'state-customer-complete',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', OperationStatus::Pending->value);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', OperationStatus::Completed->value)
        ->assertJsonPath('data.supplier_settlement_status', OperationSupplierSettlementStatus::Unsettled->value);

    expect($operation->refresh()->status)->toBe(OperationStatus::Completed)
        ->and($operation->completed_at)->not->toBeNull()
        ->and($operation->supplier_settlement_status)->toBe(OperationSupplierSettlementStatus::Unsettled)
        ->and($operation->supplier_settled_at)->toBeNull()
        ->and((float) $supplier->refresh()->balance_usd)->toBe(500.0);
});

test('operation completes when supplier fulfillment already exists and customer settlement is completed later', function (): void {
    $user = actingAsOperationStateUser();
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_amount' => 1000,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
        'status' => OperationStatus::Pending->value,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ])->assertOk();

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $box->id,
        'idempotency_key' => 'state-customer-later',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', OperationStatus::Completed->value);

    expect($operation->refresh()->status)->toBe(OperationStatus::Completed)
        ->and($operation->completed_at)->not->toBeNull();
});

test('direct operation without supplier completes after customer settlement', function (): void {
    $user = actingAsOperationStateUser();
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 2000]);
    $operation = Operation::factory()->boxFunded($box)->create([
        'created_by' => $user->id,
        'status' => OperationStatus::Pending->value,
        'completed_at' => null,
        'customer_amount' => 500,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/customer-settlement", [
        'customer_direction' => OperationCustomerDirection::CustomerPaysIntermediary->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'box_id' => $box->id,
        'idempotency_key' => 'state-no-supplier',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', OperationStatus::Completed->value);

    expect($operation->refresh()->status)->toBe(OperationStatus::Completed)
        ->and($operation->completed_at)->not->toBeNull();
});
