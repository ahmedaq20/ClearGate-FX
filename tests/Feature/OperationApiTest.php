<?php

use App\Models\AuditLog;
use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsOperationUser(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

function operationPayload(array $overrides = []): array
{
    $customer = Customer::factory()->create(['type' => 'customer']);
    $supplier = Customer::factory()->create(['type' => 'supplier']);

    return array_merge([
        'transaction_date' => '2026-06-15',
        'supplier_id' => $supplier->id,
        'box_id' => null,
        'customer_id' => $customer->id,
        'supplier_currency' => 'USD',
        'supplier_amount' => 1000,
        'supplier_exchange_rate' => 1,
        'customer_currency' => 'USD',
        'customer_amount' => 1000,
        'customer_exchange_rate' => 1,
        'commission_type' => 'percentage',
        'commission_rate' => 2,
        'notes' => 'Transfer to customer',
    ], $overrides);
}

test('supplier funded operation stores commission and does not affect boxes', function (): void {
    $owner = actingAsOperationUser();

    $this->postJson('/api/v1/operations', operationPayload())
        ->assertCreated()
        ->assertJsonPath('message', 'تم إنشاء العملية')
        ->assertJsonPath('data.reference_number', 'TRX-2026-00001')
        ->assertJsonPath('data.commission_amount', '20.0000')
        ->assertJsonPath('data.customer_net_amount', '980.0000')
        ->assertJsonPath('data.created_by', $owner->id);

    expect(BoxBalanceLog::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'operation.created')->count())->toBe(1);
});

test('operation requires exactly one funding source', function (): void {
    actingAsOperationUser();
    $box = Box::factory()->create();

    $this->postJson('/api/v1/operations', operationPayload([
        'supplier_id' => null,
        'box_id' => null,
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.funding_source.0', 'يجب اختيار مصدر تمويل واحد فقط: مورد أو صندوق.');

    $this->postJson('/api/v1/operations', operationPayload([
        'box_id' => $box->id,
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.funding_source.0', 'يجب اختيار مصدر تمويل واحد فقط: مورد أو صندوق.');
});

test('box funded operation deducts from box and writes balance log', function (): void {
    $owner = actingAsOperationUser();
    $box = Box::factory()->create(['current_balance' => 1500]);

    $this->postJson('/api/v1/operations', operationPayload([
        'supplier_id' => null,
        'box_id' => $box->id,
        'commission_type' => 'fixed',
        'commission_rate' => 25,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.reference_number', 'TRX-2026-00001')
        ->assertJsonPath('data.commission_amount', '25.0000')
        ->assertJsonPath('data.customer_net_amount', '975.0000');

    $log = BoxBalanceLog::query()->where('box_id', $box->id)->firstOrFail();

    expect((float) $box->refresh()->current_balance)->toBe(500.0)
        ->and($log->operation_type->value)->toBe('subtract')
        ->and((float) $log->amount)->toBe(1000.0)
        ->and((float) $log->balance_before)->toBe(1500.0)
        ->and((float) $log->balance_after)->toBe(500.0)
        ->and($log->created_by)->toBe($owner->id)
        ->and($log->operation_id)->toBe(Operation::query()->firstOrFail()->id);
});

test('box funded operation cannot overdraw selected box', function (): void {
    actingAsOperationUser();
    $box = Box::factory()->create(['current_balance' => 50]);

    $this->postJson('/api/v1/operations', operationPayload([
        'supplier_id' => null,
        'box_id' => $box->id,
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.box_id.0', 'رصيد الصندوق غير كافٍ.');

    expect((float) $box->refresh()->current_balance)->toBe(50.0)
        ->and(Operation::query()->count())->toBe(0)
        ->and(BoxBalanceLog::query()->count())->toBe(0);
});

test('updating operation reverses old box funding and applies new funding', function (): void {
    $owner = actingAsOperationUser();
    $oldBox = Box::factory()->create(['current_balance' => 2000]);
    $newBox = Box::factory()->create(['current_balance' => 3000]);
    $operation = Operation::factory()
        ->boxFunded($oldBox)
        ->create([
            'customer_amount' => 1000,
            'commission_amount' => 20,
            'customer_net_amount' => 980,
            'created_by' => $owner->id,
        ]);
    $oldBox->update(['current_balance' => 1000]);

    $this->putJson("/api/v1/operations/{$operation->id}", [
        'box_id' => $newBox->id,
        'customer_amount' => 1200,
        'commission_type' => 'percentage',
        'commission_rate' => 10,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث العملية')
        ->assertJsonPath('data.box_id', $newBox->id)
        ->assertJsonPath('data.commission_amount', '120.0000')
        ->assertJsonPath('data.customer_net_amount', '1080.0000');

    expect((float) $oldBox->refresh()->current_balance)->toBe(2000.0)
        ->and((float) $newBox->refresh()->current_balance)->toBe(1800.0)
        ->and(BoxBalanceLog::query()->where('operation_id', $operation->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'operation.updated')->count())->toBe(1);
});

test('deleting box funded operation reverses box balance and records audit', function (): void {
    $owner = actingAsOperationUser();
    $box = Box::factory()->create(['current_balance' => 500]);
    $operation = Operation::factory()
        ->boxFunded($box)
        ->create([
            'customer_amount' => 1000,
            'created_by' => $owner->id,
        ]);

    $this->deleteJson("/api/v1/operations/{$operation->id}")
        ->assertOk()
        ->assertJsonPath('message', 'تم حذف العملية');

    expect((float) $box->refresh()->current_balance)->toBe(1500.0)
        ->and(Operation::query()->whereKey($operation->id)->exists())->toBeFalse()
        ->and(BoxBalanceLog::query()->where('box_id', $box->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'operation.deleted')->count())->toBe(1);
});

test('operations can be filtered and receipt can be returned', function (): void {
    $owner = actingAsOperationUser();
    $customer = Customer::factory()->create(['type' => 'customer']);
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $matching = Operation::factory()->create([
        'reference_number' => 'TRX-2026-00009',
        'transaction_date' => '2026-06-10',
        'customer_id' => $customer->id,
        'supplier_id' => $supplier->id,
        'created_by' => $owner->id,
    ]);
    Operation::factory()->create(['transaction_date' => '2026-05-10']);

    $this->getJson("/api/v1/operations?customer={$customer->id}&supplier={$supplier->id}&date_from=2026-06-01&date_to=2026-06-30&reference_number=TRX-2026-00009")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $matching->id);

    $this->getJson("/api/v1/operations/{$matching->id}/receipt")
        ->assertOk()
        ->assertJsonPath('data.operation.id', $matching->id)
        ->assertJsonPath('data.operation.reference_number', 'TRX-2026-00009');
});
