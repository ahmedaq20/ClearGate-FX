<?php

use App\Enums\OperationStatus;
use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\CapitalAccount;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsDashboardUser(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

function dashboardCustomer(User $user, string $type, string $name): Customer
{
    $vault = $user->vault ?? Vault::factory()->create(['user_id' => $user->id]);

    return Customer::factory()->create([
        'user_id' => $user->id,
        'vault_id' => $vault->id,
        'type' => $type,
        'name' => $name,
    ]);
}

function dashboardOperation(array $overrides = []): Operation
{
    static $referenceNumber = 1;

    $customerId = $overrides['customer_id'] ?? Customer::factory()->create(['type' => 'customer'])->id;
    $createdBy = $overrides['created_by'] ?? User::factory();

    return Operation::query()->create(array_merge([
        'reference_number' => sprintf('TRX-2026-D%04d', $referenceNumber++),
        'transaction_date' => now()->toDateString(),
        'supplier_id' => null,
        'box_id' => null,
        'customer_id' => $customerId,
        'supplier_currency' => null,
        'supplier_amount' => null,
        'supplier_exchange_rate' => null,
        'customer_currency' => 'USD',
        'customer_amount' => 100,
        'customer_exchange_rate' => 1,
        'commission_type' => 'fixed',
        'commission_rate' => 0,
        'commission_amount' => 0,
        'customer_net_amount' => 100,
        'status' => OperationStatus::Pending->value,
        'completed_at' => null,
        'cancelled_at' => null,
        'cancellation_reason' => null,
        'notes' => null,
        'created_by' => $createdBy,
    ], $overrides));
}

test('owner can view financial dashboard summary and pending operation widget', function (): void {
    $owner = actingAsDashboardUser();
    $supplier = dashboardCustomer($owner, 'supplier', 'محمد');
    $customer = dashboardCustomer($owner, 'customer', 'أحمد');
    $box = Box::factory()->create(['current_balance' => 1500]);
    Box::factory()->create(['current_balance' => 700]);
    CapitalAccount::factory()->create([
        'user_id' => $owner->id,
        'balance_usd' => 3200,
        'free_balance_usd' => 1000,
    ]);

    dashboardOperation([
        'reference_number' => 'TRX-2026-00025',
        'transaction_date' => now()->subDays(3)->toDateString(),
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'supplier_amount' => 1000,
        'customer_amount' => 1000,
        'commission_amount' => 20,
        'status' => OperationStatus::Pending->value,
        'created_by' => $owner->id,
    ]);

    dashboardOperation([
        'transaction_date' => now()->toDateString(),
        'box_id' => $box->id,
        'customer_id' => $customer->id,
        'supplier_id' => null,
        'supplier_currency' => null,
        'supplier_amount' => null,
        'supplier_exchange_rate' => null,
        'customer_amount' => 500,
        'commission_amount' => 25,
        'customer_net_amount' => 475,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    $this->getJson('/api/v1/dashboard/financial')
        ->assertOk()
        ->assertJsonPath('data.capital_balance', 3200)
        ->assertJsonPath('data.free_capital', 1000)
        ->assertJsonPath('data.total_boxes_balance', 2200)
        ->assertJsonPath('data.reconciliation_status', 'balanced')
        ->assertJsonPath('data.reconciliation_difference', 0)
        ->assertJsonPath('data.pending_operations_count', 1)
        ->assertJsonPath('data.pending_operations_amount', 1000)
        ->assertJsonPath('data.completed_operations_count', 1)
        ->assertJsonPath('data.completed_operations_amount', 500)
        ->assertJsonPath('data.today_operations_count', 1)
        ->assertJsonPath('data.today_operations_amount', 500)
        ->assertJsonPath('data.today_commissions', 25)
        ->assertJsonPath('data.suppliers_count', 1)
        ->assertJsonPath('data.customers_count', 1)
        ->assertJsonPath('data.boxes_count', 2)
        ->assertJsonPath('data.top_pending_operations.0.reference_number', 'TRX-2026-00025')
        ->assertJsonPath('data.top_pending_operations.0.supplier', 'محمد')
        ->assertJsonPath('data.top_pending_operations.0.customer', 'أحمد')
        ->assertJsonPath('data.top_pending_operations.0.amount', 1000)
        ->assertJsonPath('data.top_pending_operations.0.pending_days', 3);
});

test('financial dashboard filters operations by supplier box and dates', function (): void {
    $owner = actingAsDashboardUser();
    $supplier = dashboardCustomer($owner, 'supplier', 'Supplier A');
    $otherSupplier = dashboardCustomer($owner, 'supplier', 'Supplier B');
    $customer = dashboardCustomer($owner, 'customer', 'Customer A');
    $box = Box::factory()->create(['current_balance' => 1000]);
    $otherBox = Box::factory()->create(['current_balance' => 3000]);

    dashboardOperation([
        'transaction_date' => '2026-06-10',
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'supplier_amount' => 800,
        'customer_amount' => 800,
        'commission_amount' => 8,
        'customer_net_amount' => 792,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    dashboardOperation([
        'transaction_date' => '2026-06-01',
        'supplier_id' => $otherSupplier->id,
        'customer_id' => $customer->id,
        'customer_amount' => 1200,
        'commission_amount' => 12,
        'customer_net_amount' => 1188,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    $this->getJson("/api/v1/dashboard/financial?date_from=2026-06-05&date_to=2026-06-30&supplier_id={$supplier->id}")
        ->assertOk()
        ->assertJsonPath('data.completed_operations_count', 1)
        ->assertJsonPath('data.completed_operations_amount', 800)
        ->assertJsonPath('data.suppliers_count', 1);

    $this->getJson("/api/v1/dashboard/financial?box_id={$box->id}")
        ->assertOk()
        ->assertJsonPath('data.total_boxes_balance', 1000)
        ->assertJsonPath('data.boxes_count', 1);
});

test('supplier box commission and chart dashboard endpoints return chart ready data', function (): void {
    $owner = actingAsDashboardUser();
    $supplier = dashboardCustomer($owner, 'supplier', 'Top Supplier');
    $customer = dashboardCustomer($owner, 'customer', 'Customer A');
    $box = Box::factory()->create([
        'name' => 'Main Box',
        'type' => 'turkish',
        'current_balance' => 900,
    ]);

    dashboardOperation([
        'transaction_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'supplier_amount' => 1000,
        'customer_amount' => 1000,
        'commission_amount' => 40,
        'status' => OperationStatus::Pending->value,
        'created_by' => $owner->id,
    ]);

    dashboardOperation([
        'transaction_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'customer_id' => $customer->id,
        'supplier_amount' => 500,
        'customer_amount' => 500,
        'commission_amount' => 30,
        'customer_net_amount' => 470,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    BoxBalanceLog::factory()->create([
        'box_id' => $box->id,
        'created_by' => $owner->id,
        'created_at' => now()->subHour(),
    ]);

    $this->getJson('/api/v1/dashboard/suppliers')
        ->assertOk()
        ->assertJsonPath('data.total_suppliers', 1)
        ->assertJsonPath('data.suppliers_with_pending_operations', 1)
        ->assertJsonPath('data.top_suppliers_by_volume.0.name', 'Top Supplier')
        ->assertJsonPath('data.top_suppliers_by_volume.0.total', 1500)
        ->assertJsonPath('data.top_suppliers_by_commission.0.total', 70);

    $this->getJson('/api/v1/dashboard/boxes')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Main Box')
        ->assertJsonPath('data.0.type', 'turkish')
        ->assertJsonPath('data.0.current_balance', 900)
        ->assertJsonStructure(['data' => [['last_activity_date']]]);

    $this->getJson('/api/v1/dashboard/commissions')
        ->assertOk()
        ->assertJsonPath('data.today_commissions', 70)
        ->assertJsonPath('data.monthly_commissions', 70)
        ->assertJsonPath('data.yearly_commissions', 70);

    $this->getJson('/api/v1/dashboard/charts')
        ->assertOk()
        ->assertJsonPath('data.operations_by_day.0.count', 2)
        ->assertJsonPath('data.operations_by_day.0.amount', 1500)
        ->assertJsonPath('data.commissions_by_day.0.amount', 70)
        ->assertJsonPath('data.pending_vs_completed.pending.count', 1)
        ->assertJsonPath('data.pending_vs_completed.completed.count', 1);
});

test('manager with dashboard permission is scoped to own data and other roles are forbidden', function (): void {
    $manager = actingAsDashboardUser('manager');
    $managerSupplier = dashboardCustomer($manager, 'supplier', 'Manager Supplier');
    $managerCustomer = dashboardCustomer($manager, 'customer', 'Manager Customer');
    $managerBox = Box::factory()->create([
        'current_balance' => 400,
        'assigned_user_id' => $manager->id,
    ]);

    dashboardOperation([
        'supplier_id' => $managerSupplier->id,
        'customer_id' => $managerCustomer->id,
        'customer_amount' => 300,
        'commission_amount' => 15,
        'customer_net_amount' => 285,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $manager->id,
    ]);

    $otherUser = User::factory()->create(['is_active' => true]);
    $otherSupplier = dashboardCustomer($otherUser, 'supplier', 'Other Supplier');
    $otherCustomer = dashboardCustomer($otherUser, 'customer', 'Other Customer');
    Box::factory()->create(['current_balance' => 900]);
    dashboardOperation([
        'supplier_id' => $otherSupplier->id,
        'customer_id' => $otherCustomer->id,
        'customer_amount' => 900,
        'commission_amount' => 45,
        'customer_net_amount' => 855,
        'status' => OperationStatus::Completed->value,
        'completed_at' => now(),
        'created_by' => $otherUser->id,
    ]);

    $this->getJson('/api/v1/dashboard/financial')
        ->assertOk()
        ->assertJsonPath('data.total_boxes_balance', 400)
        ->assertJsonPath('data.completed_operations_count', 1)
        ->assertJsonPath('data.completed_operations_amount', 300)
        ->assertJsonPath('data.suppliers_count', 1)
        ->assertJsonPath('data.customers_count', 1)
        ->assertJsonPath('data.boxes_count', 1);

    actingAsDashboardUser('operations_employee');

    $this->getJson('/api/v1/dashboard/financial')
        ->assertForbidden()
        ->assertJsonPath('message', 'غير مصرح');
});
