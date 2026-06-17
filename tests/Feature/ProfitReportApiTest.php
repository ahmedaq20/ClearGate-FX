<?php

use App\Enums\OperationStatus;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsProfitReportUser(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

function profitReportCustomer(User $user, string $type, string $name): Customer
{
    $vault = $user->vault ?? Vault::factory()->create(['user_id' => $user->id]);

    return Customer::factory()->create([
        'user_id' => $user->id,
        'vault_id' => $vault->id,
        'type' => $type,
        'name' => $name,
    ]);
}

function profitReportOperation(array $overrides = []): Operation
{
    static $referenceNumber = 1;

    $user = $overrides['created_by'] ?? User::factory()->create(['is_active' => true])->id;
    $customerId = $overrides['customer_id'] ?? Customer::factory()->create(['type' => 'customer'])->id;

    return Operation::query()->create(array_merge([
        'reference_number' => sprintf('TRX-2026-P%04d', $referenceNumber++),
        'transaction_date' => '2026-06-17',
        'supplier_id' => null,
        'box_id' => null,
        'customer_id' => $customerId,
        'supplier_currency' => null,
        'supplier_amount' => null,
        'supplier_exchange_rate' => null,
        'customer_currency' => 'USD',
        'customer_amount' => 1000,
        'customer_exchange_rate' => 1,
        'commission_type' => 'fixed',
        'commission_rate' => 0,
        'commission_amount' => 0,
        'customer_net_amount' => 1000,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'cancelled_at' => null,
        'cancellation_reason' => null,
        'notes' => null,
        'created_by' => $user,
    ], $overrides));
}

test('profit summary includes only completed operation commissions as usd profit', function (): void {
    $owner = actingAsProfitReportUser();
    $supplier = profitReportCustomer($owner, 'supplier', 'Supplier A');
    $customer = profitReportCustomer($owner, 'customer', 'Customer A');

    profitReportOperation([
        'transaction_date' => '2026-06-17',
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'commission_amount' => 20,
        'customer_exchange_rate' => 1,
        'created_by' => $owner->id,
    ]);
    profitReportOperation([
        'transaction_date' => '2026-06-17',
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'commission_amount' => 40,
        'customer_exchange_rate' => 2,
        'created_by' => $owner->id,
    ]);
    profitReportOperation([
        'transaction_date' => '2026-06-17',
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'commission_amount' => 999,
        'status' => OperationStatus::Pending->value,
        'completed_at' => null,
        'created_by' => $owner->id,
    ]);
    profitReportOperation([
        'transaction_date' => '2026-06-17',
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'commission_amount' => 888,
        'status' => OperationStatus::Cancelled->value,
        'completed_at' => null,
        'cancelled_at' => now(),
        'created_by' => $owner->id,
    ]);

    $this->getJson('/api/v1/reports/profit-summary')
        ->assertOk()
        ->assertJsonPath('data.total_operations', 4)
        ->assertJsonPath('data.completed_operations', 2)
        ->assertJsonPath('data.pending_operations', 1)
        ->assertJsonPath('data.cancelled_operations', 1)
        ->assertJsonPath('data.total_profit_usd', 40);
});

test('daily and monthly profit reports return completed commission totals', function (): void {
    $owner = actingAsProfitReportUser();
    $customer = profitReportCustomer($owner, 'customer', 'Customer A');

    profitReportOperation([
        'transaction_date' => '2026-06-10',
        'customer_id' => $customer->id,
        'commission_amount' => 10,
        'customer_exchange_rate' => 1,
        'created_by' => $owner->id,
    ]);
    profitReportOperation([
        'transaction_date' => '2026-06-11',
        'customer_id' => $customer->id,
        'commission_amount' => 30,
        'customer_exchange_rate' => 3,
        'created_by' => $owner->id,
    ]);
    profitReportOperation([
        'transaction_date' => '2026-07-01',
        'customer_id' => $customer->id,
        'commission_amount' => 50,
        'customer_exchange_rate' => 2,
        'created_by' => $owner->id,
    ]);

    $this->getJson('/api/v1/reports/daily-profit?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->assertJsonPath('data.rows.0.date', '2026-06-10')
        ->assertJsonPath('data.rows.0.total_profit_usd', 10)
        ->assertJsonPath('data.rows.1.date', '2026-06-11')
        ->assertJsonPath('data.rows.1.total_profit_usd', 10)
        ->assertJsonPath('data.total_profit_usd', 20);

    $this->getJson('/api/v1/reports/monthly-profit')
        ->assertOk()
        ->assertJsonPath('data.rows.0.month', '2026-06')
        ->assertJsonPath('data.rows.0.operations_count', 2)
        ->assertJsonPath('data.rows.0.total_profit_usd', 20)
        ->assertJsonPath('data.rows.1.month', '2026-07')
        ->assertJsonPath('data.rows.1.total_profit_usd', 25)
        ->assertJsonPath('data.total_profit_usd', 45);
});

test('profit by supplier and user reports support filters', function (): void {
    $owner = actingAsProfitReportUser();
    $employee = User::factory()->create(['name' => 'Employee A', 'is_active' => true]);
    $otherEmployee = User::factory()->create(['name' => 'Employee B', 'is_active' => true]);
    $supplier = profitReportCustomer($owner, 'supplier', 'Supplier A');
    $otherSupplier = profitReportCustomer($owner, 'supplier', 'Supplier B');
    $customer = profitReportCustomer($owner, 'customer', 'Customer A');

    profitReportOperation([
        'transaction_date' => '2026-06-10',
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'commission_amount' => 20,
        'customer_exchange_rate' => 1,
        'created_by' => $employee->id,
    ]);
    profitReportOperation([
        'transaction_date' => '2026-06-11',
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'commission_amount' => 30,
        'customer_exchange_rate' => 3,
        'created_by' => $employee->id,
    ]);
    profitReportOperation([
        'transaction_date' => '2026-06-12',
        'supplier_id' => $otherSupplier->id,
        'customer_id' => $customer->id,
        'commission_amount' => 50,
        'customer_exchange_rate' => 1,
        'created_by' => $otherEmployee->id,
    ]);

    $this->getJson("/api/v1/reports/profit-by-supplier?supplier_id={$supplier->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.rows.0.supplier', 'Supplier A')
        ->assertJsonPath('data.rows.0.operations_count', 2)
        ->assertJsonPath('data.rows.0.total_profit_usd', 30);

    $this->getJson("/api/v1/reports/profit-by-user?user_id={$employee->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.rows.0.employee', 'Employee A')
        ->assertJsonPath('data.rows.0.operations_count', 2)
        ->assertJsonPath('data.rows.0.total_profit_usd', 30);
});

test('manager profit report is scoped to own completed operations and profit export can be queued', function (): void {
    Queue::fake();

    $manager = actingAsProfitReportUser('manager');
    $managerCustomer = profitReportCustomer($manager, 'customer', 'Manager Customer');
    $otherUser = User::factory()->create(['is_active' => true]);
    $otherCustomer = profitReportCustomer($otherUser, 'customer', 'Other Customer');

    profitReportOperation([
        'customer_id' => $managerCustomer->id,
        'commission_amount' => 60,
        'customer_exchange_rate' => 2,
        'created_by' => $manager->id,
    ]);
    profitReportOperation([
        'customer_id' => $otherCustomer->id,
        'commission_amount' => 100,
        'customer_exchange_rate' => 1,
        'created_by' => $otherUser->id,
    ]);

    $this->getJson('/api/v1/reports/profit-summary')
        ->assertOk()
        ->assertJsonPath('data.total_operations', 1)
        ->assertJsonPath('data.completed_operations', 1)
        ->assertJsonPath('data.total_profit_usd', 30);

    $this->postJson('/api/v1/reports/export', [
        'type' => 'profit-summary',
        'format' => 'pdf',
        'params' => [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ],
    ])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonStructure(['data' => ['job_id', 'status_url']]);
});
