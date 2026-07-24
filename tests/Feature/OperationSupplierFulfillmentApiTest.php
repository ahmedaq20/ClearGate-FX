<?php

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierFulfillmentStatus;
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

function actingAsSupplierFulfillmentUser(string $role = 'manager'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

test('supplier fulfillment can be marked pending without creating obligations or cash movement', function (): void {
    $user = actingAsSupplierFulfillmentUser();
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_amount' => 50000,
        'supplier_currency' => 'EGP',
        'supplier_exchange_rate' => 50,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Pending->value,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث تنفيذ المورد')
        ->assertJsonPath('data.supplier_fulfillment_status', OperationSupplierFulfillmentStatus::Pending->value)
        ->assertJsonPath('data.supplier_fulfilled_at', null)
        ->assertJsonPath('data.status', OperationStatus::Pending->value);

    expect(OperationObligation::query()->count())->toBe(0)
        ->and(OperationSettlement::query()->count())->toBe(0)
        ->and(BoxBalanceLog::query()->count())->toBe(0);
});

test('completed supplier fulfillment creates a supplier payable without completing the operation or moving cash', function (): void {
    $user = actingAsSupplierFulfillmentUser();
    $supplier = Customer::factory()->create(['type' => 'supplier', 'balance_usd' => 700]);
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 1500]);
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_id' => $supplier->id,
        'supplier_amount' => 50000,
        'supplier_currency' => 'EGP',
        'supplier_exchange_rate' => 50,
        'status' => OperationStatus::Pending->value,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.supplier_fulfillment_status', OperationSupplierFulfillmentStatus::Completed->value)
        ->assertJsonPath('data.status', OperationStatus::Pending->value);

    $obligation = OperationObligation::query()->firstOrFail();

    expect($operation->refresh()->supplier_fulfilled_at)->not->toBeNull()
        ->and($operation->status)->toBe(OperationStatus::Pending)
        ->and($obligation->operation_id)->toBe($operation->id)
        ->and($obligation->counterparty_id)->toBe($supplier->id)
        ->and($obligation->counterparty_role)->toBe(OperationCounterpartyRole::Supplier)
        ->and($obligation->type)->toBe(OperationObligationType::Payable)
        ->and($obligation->reason)->toBe(OperationObligationReason::SupplierSettlement)
        ->and($obligation->status)->toBe(OperationObligationStatus::Open)
        ->and($obligation->currency)->toBe('EGP')
        ->and((float) $obligation->amount)->toBe(50000.0)
        ->and((float) $obligation->exchange_rate)->toBe(50.0)
        ->and((float) $supplier->refresh()->balance_usd)->toBe(700.0)
        ->and((float) $box->refresh()->current_balance)->toBe(1500.0)
        ->and(OperationSettlement::query()->count())->toBe(0)
        ->and(BoxBalanceLog::query()->count())->toBe(0);
});

test('completed supplier fulfillment is idempotent for the same operation supplier and amount', function (): void {
    $user = actingAsSupplierFulfillmentUser();
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_amount' => 1000,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
    ]);
    $payload = [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ];

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", $payload)->assertOk();
    $fulfilledAt = $operation->refresh()->supplier_fulfilled_at;

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", $payload)->assertOk();

    expect(OperationObligation::query()->count())->toBe(1)
        ->and($operation->refresh()->supplier_fulfilled_at->equalTo($fulfilledAt))->toBeTrue()
        ->and(OperationSettlement::query()->count())->toBe(0)
        ->and(BoxBalanceLog::query()->count())->toBe(0);
});

test('supplier fulfillment rejects box funded operations and cancelled operations', function (): void {
    $user = actingAsSupplierFulfillmentUser();
    $boxFundedOperation = Operation::factory()
        ->boxFunded()
        ->create(['created_by' => $user->id]);
    $cancelledOperation = Operation::factory()
        ->cancelled()
        ->create(['created_by' => $user->id]);
    $payload = [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ];

    $this->postJson("/api/v1/operations/{$boxFundedOperation->id}/supplier-fulfillment", $payload)
        ->assertUnprocessable();

    $this->postJson("/api/v1/operations/{$cancelledOperation->id}/supplier-fulfillment", $payload)
        ->assertUnprocessable();

    expect(OperationObligation::query()->count())->toBe(0)
        ->and(OperationSettlement::query()->count())->toBe(0);
});

test('supplier fulfillment cannot be reverted to pending after completion', function (): void {
    $user = actingAsSupplierFulfillmentUser();
    $operation = Operation::factory()->create([
        'created_by' => $user->id,
        'supplier_amount' => 1000,
        'supplier_currency' => 'USD',
        'supplier_exchange_rate' => 1,
    ]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ])->assertOk();

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Pending->value,
    ])->assertUnprocessable();

    expect($operation->refresh()->supplier_fulfillment_status)->toBe(OperationSupplierFulfillmentStatus::Completed)
        ->and(OperationObligation::query()->count())->toBe(1);
});

test('supplier fulfillment is limited to accessible operations', function (): void {
    $owner = actingAsSupplierFulfillmentUser('owner');
    $otherUser = User::factory()->create(['is_active' => true]);
    $operation = Operation::factory()->create(['created_by' => $otherUser->id]);

    $this->postJson("/api/v1/operations/{$operation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
    ])->assertOk();

    $manager = actingAsSupplierFulfillmentUser();
    $anotherOperation = Operation::factory()->create(['created_by' => $otherUser->id]);

    expect($manager->id)->not->toBe($anotherOperation->created_by)
        ->and($owner->isOwner())->toBeTrue();

    $this->postJson("/api/v1/operations/{$anotherOperation->id}/supplier-fulfillment", [
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Pending->value,
    ])->assertForbidden();
});
