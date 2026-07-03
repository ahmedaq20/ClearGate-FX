<?php

use App\Enums\OperationStatus;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsOperationReportUser(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

function operationReportCustomer(User $user, string $type, string $name): Customer
{
    $vault = $user->vault ?? Vault::factory()->create(['user_id' => $user->id]);

    return Customer::factory()->create([
        'user_id' => $user->id,
        'vault_id' => $vault->id,
        'type' => $type,
        'name' => $name,
    ]);
}

function operationReportOperation(array $overrides = []): Operation
{
    static $referenceNumber = 1;

    return Operation::factory()->create(array_merge([
        'reference_number' => sprintf('TRX-2026-R%04d', $referenceNumber++),
        'transaction_date' => '2026-06-15',
        'customer_amount' => 1000,
        'commission_amount' => 25,
        'customer_net_amount' => 975,
    ], $overrides));
}

test('operation report endpoints aggregate operations with filters and statuses', function (): void {
    $owner = actingAsOperationReportUser();
    $supplier = operationReportCustomer($owner, 'supplier', 'Supplier A');
    $customer = operationReportCustomer($owner, 'customer', 'Customer A');
    $box = Box::factory()->create(['name' => 'Main Box', 'current_balance' => 5000]);

    operationReportOperation([
        'supplier_id' => $supplier->id,
        'box_id' => null,
        'customer_id' => $customer->id,
        'supplier_amount' => 1000,
        'customer_amount' => 1000,
        'commission_amount' => 25,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    operationReportOperation([
        'supplier_id' => $supplier->id,
        'box_id' => null,
        'customer_id' => $customer->id,
        'supplier_amount' => 700,
        'customer_amount' => 700,
        'commission_amount' => 10,
        'customer_net_amount' => 690,
        'status' => OperationStatus::Pending->value,
        'created_by' => $owner->id,
    ]);

    operationReportOperation([
        'supplier_id' => $supplier->id,
        'box_id' => null,
        'customer_id' => $customer->id,
        'supplier_amount' => 300,
        'customer_amount' => 300,
        'commission_amount' => 99,
        'customer_net_amount' => 201,
        'status' => OperationStatus::Cancelled->value,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Duplicate request',
        'created_by' => $owner->id,
    ]);

    operationReportOperation([
        'supplier_id' => null,
        'box_id' => $box->id,
        'customer_id' => $customer->id,
        'supplier_amount' => null,
        'customer_amount' => 400,
        'commission_amount' => 15,
        'customer_net_amount' => 385,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    $this->getJson('/api/v1/reports/operations?date_from=2026-06-01&date_to=2026-06-30&group_by_status=1')
        ->assertOk()
        ->assertJsonPath('data.total_operations', 4)
        ->assertJsonPath('data.completed', 2)
        ->assertJsonPath('data.pending', 1)
        ->assertJsonPath('data.cancelled', 1)
        ->assertJsonPath('data.total_transferred_amount', 2400)
        ->assertJsonCount(3, 'data.by_status');

    $this->getJson('/api/v1/reports/commissions?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->assertJsonPath('data.total_commission', 40)
        ->assertJsonPath('data.average_commission', 20)
        ->assertJsonPath('data.operation_count', 2)
        ->assertJsonPath('data.currency', 'USD');

    $this->getJson('/api/v1/reports/suppliers')
        ->assertOk()
        ->assertJsonPath('data.rows.0.supplier', 'Supplier A')
        ->assertJsonPath('data.rows.0.operation_count', 3)
        ->assertJsonPath('data.rows.0.completed_count', 1)
        ->assertJsonPath('data.rows.0.pending_count', 1)
        ->assertJsonPath('data.rows.0.cancelled_count', 1)
        ->assertJsonPath('data.rows.0.transferred_amount', 2000)
        ->assertJsonPath('data.rows.0.total_commissions', 25);

    $this->getJson('/api/v1/reports/customers')
        ->assertOk()
        ->assertJsonPath('data.rows.0.customer', 'Customer A')
        ->assertJsonPath('data.rows.0.operation_count', 4)
        ->assertJsonPath('data.rows.0.total_received_amount', 2251)
        ->assertJsonPath('data.rows.0.total_sent_amount', 2000)
        ->assertJsonPath('data.rows.0.last_operation', '2026-06-15');

    $this->getJson('/api/v1/reports/boxes')
        ->assertOk()
        ->assertJsonPath('data.rows.0.box', 'Main Box')
        ->assertJsonPath('data.rows.0.current_balance', 5000)
        ->assertJsonPath('data.rows.0.operations_count', 1)
        ->assertJsonPath('data.rows.0.outgoing_amount', 400);

    $this->getJson('/api/v1/reports/pending')
        ->assertOk()
        ->assertJsonPath('data.operations.0.supplier', 'Supplier A')
        ->assertJsonPath('data.operations.0.customer', 'Customer A')
        ->assertJsonPath('data.operations.0.amount', 700)
        ->assertJsonPath('data.operations.0.commission', 10);

    $this->getJson('/api/v1/reports/cancelled')
        ->assertOk()
        ->assertJsonPath('data.operations.0.cancellation_reason', 'Duplicate request')
        ->assertJsonPath('data.operations.0.amount', 300);
});

test('dashboard summary uses operation kpis', function (): void {
    $owner = actingAsOperationReportUser();
    $supplier = operationReportCustomer($owner, 'supplier', 'Supplier A');
    $customer = operationReportCustomer($owner, 'customer', 'Customer A');

    operationReportOperation([
        'transaction_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'customer_amount' => 1000,
        'commission_amount' => 20,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    operationReportOperation([
        'transaction_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'customer_amount' => 500,
        'commission_amount' => 10,
        'status' => OperationStatus::Pending->value,
        'created_by' => $owner->id,
    ]);

    operationReportOperation([
        'transaction_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'customer_amount' => 250,
        'commission_amount' => 5,
        'status' => OperationStatus::Cancelled->value,
        'cancelled_at' => now(),
        'created_by' => $owner->id,
    ]);

    $this->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('data.today_operations', 3)
        ->assertJsonPath('data.today_completed', 1)
        ->assertJsonPath('data.today_pending', 1)
        ->assertJsonPath('data.today_cancelled', 1)
        ->assertJsonPath('data.today_commission', 20)
        ->assertJsonPath('data.month_commission', 20)
        ->assertJsonPath('data.active_suppliers', 1)
        ->assertJsonPath('data.active_customers', 1);
});
