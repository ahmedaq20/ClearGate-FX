<?php

use App\Enums\BoxBalanceOperationType;
use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationCustomerSettlementStatus;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationSettlementDirection;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierFulfillmentStatus;
use App\Enums\OperationSupplierSettlementStatus;
use App\Jobs\GenerateReportJob;
use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsWorkflowReportUser(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

function workflowReportCustomer(User $user, string $type, string $name): Customer
{
    return Customer::factory()->create([
        'user_id' => $user->id,
        'type' => $type,
        'name' => $name,
    ]);
}

function workflowReportOperation(User $user, Customer $customer, ?Customer $supplier = null, array $overrides = []): Operation
{
    static $referenceNumber = 1;

    return Operation::factory()->create(array_merge([
        'reference_number' => sprintf('TRX-2026-W%04d', $referenceNumber++),
        'transaction_date' => '2026-07-10',
        'customer_id' => $customer->id,
        'supplier_id' => $supplier?->id,
        'customer_amount' => 1000,
        'customer_currency' => 'USD',
        'customer_exchange_rate' => 1,
        'supplier_amount' => $supplier === null ? null : 50000,
        'supplier_currency' => $supplier === null ? null : 'EGP',
        'supplier_exchange_rate' => $supplier === null ? null : 50,
        'commission_amount' => 25,
        'commission_currency' => 'USD',
        'status' => OperationStatus::Pending->value,
        'created_by' => $user->id,
    ], $overrides));
}

test('obligation report keeps receivables and payables currency aware', function (): void {
    $owner = actingAsWorkflowReportUser();
    $customer = workflowReportCustomer($owner, 'customer', 'Gaza Customer');
    $supplier = workflowReportCustomer($owner, 'supplier', 'Egypt Supplier');
    $operation = workflowReportOperation($owner, $customer, $supplier);

    OperationObligation::factory()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $customer->id,
        'counterparty_role' => OperationCounterpartyRole::Customer->value,
        'type' => OperationObligationType::Receivable->value,
        'reason' => OperationObligationReason::CustomerPrincipal->value,
        'amount' => 1000,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'settled_amount' => 0,
        'balance_amount' => 1000,
        'status' => OperationObligationStatus::Open->value,
        'created_by' => $owner->id,
    ]);
    OperationObligation::factory()->payable()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'amount' => 50000,
        'currency' => 'EGP',
        'exchange_rate' => 50,
        'settled_amount' => 10000,
        'balance_amount' => 40000,
        'status' => OperationObligationStatus::PartiallySettled->value,
        'created_by' => $owner->id,
    ]);

    $data = $this->getJson('/api/v1/reports/obligations?date_from=2026-07-01&date_to=2026-07-31')
        ->assertOk()
        ->json('data');

    $currencyTotals = collect($data['currency_totals']);

    expect($data['meta']['total'])->toBe(2)
        ->and($currencyTotals)->toHaveCount(2)
        ->and($currencyTotals->firstWhere('currency', 'USD')['balance_amount'])->toBe(1000)
        ->and($currencyTotals->firstWhere('currency', 'EGP')['balance_amount'])->toBe(40000);

    $filtered = $this->getJson('/api/v1/reports/obligations?currency=USD&obligation_type=receivable&counterparty_role=customer')
        ->assertOk()
        ->json('data');

    expect($filtered['meta']['total'])->toBe(1)
        ->and($filtered['rows'][0]['currency'])->toBe('USD')
        ->and($filtered['rows'][0]['type'])->toBe(OperationObligationType::Receivable->value)
        ->and($filtered['rows'][0]['counterparty_role'])->toBe(OperationCounterpartyRole::Customer->value);
});

test('workflow report exposes independent operation financial states and outstanding balances', function (): void {
    $owner = actingAsWorkflowReportUser();
    $customer = workflowReportCustomer($owner, 'customer', 'Sender');
    $supplier = workflowReportCustomer($owner, 'supplier', 'Supplier');
    $operation = workflowReportOperation($owner, $customer, $supplier, [
        'status' => OperationStatus::Completed->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
        'supplier_settlement_status' => OperationSupplierSettlementStatus::Unsettled->value,
        'completed_at' => now(),
    ]);

    OperationObligation::factory()->payable()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $supplier->id,
        'amount' => 50000,
        'currency' => 'EGP',
        'exchange_rate' => 50,
        'settled_amount' => 0,
        'balance_amount' => 50000,
        'status' => OperationObligationStatus::Open->value,
        'created_by' => $owner->id,
    ]);

    $row = $this->getJson('/api/v1/reports/operations-workflow')
        ->assertOk()
        ->json('data.rows.0');

    expect($row['reference_number'])->toBe($operation->reference_number)
        ->and($row['status'])->toBe(OperationStatus::Completed->value)
        ->and($row['customer_settlement_status'])->toBe(OperationCustomerSettlementStatus::Completed->value)
        ->and($row['supplier_fulfillment_status'])->toBe(OperationSupplierFulfillmentStatus::Completed->value)
        ->and($row['supplier_settlement_status'])->toBe(OperationSupplierSettlementStatus::Unsettled->value)
        ->and($row['customer_currency'])->toBe('USD')
        ->and($row['supplier_currency'])->toBe('EGP')
        ->and($row['outstanding'][0]['type'])->toBe(OperationObligationType::Payable->value)
        ->and($row['outstanding'][0]['currency'])->toBe('EGP')
        ->and($row['outstanding'][0]['balance_amount'])->toBe(50000);
});

test('workflow reconciliation reports ledger settlement and status mismatches', function (): void {
    $owner = actingAsWorkflowReportUser();
    $customer = workflowReportCustomer($owner, 'customer', 'Customer');
    $supplier = workflowReportCustomer($owner, 'supplier', 'Supplier');
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 1000]);
    $operation = workflowReportOperation($owner, $customer, $supplier, [
        'status' => OperationStatus::Completed->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Pending->value,
        'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Pending->value,
    ]);
    $obligation = OperationObligation::factory()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $customer->id,
        'amount' => 1000,
        'currency' => 'USD',
        'settled_amount' => 100,
        'balance_amount' => 100,
        'status' => OperationObligationStatus::Open->value,
        'created_by' => $owner->id,
    ]);

    OperationSettlement::factory()->create([
        'operation_id' => $operation->id,
        'operation_obligation_id' => $obligation->id,
        'counterparty_id' => $customer->id,
        'counterparty_role' => OperationCounterpartyRole::Customer->value,
        'direction' => OperationSettlementDirection::CashOut->value,
        'amount' => 200,
        'currency' => 'USD',
        'box_id' => $box->id,
        'created_by' => $owner->id,
    ]);

    $data = $this->getJson('/api/v1/reports/reconciliation')
        ->assertOk()
        ->json('data');

    $issueTypes = collect($data['issues'])->pluck('type');

    expect($data['summary']['total_issues'])->toBeGreaterThanOrEqual(4)
        ->and($issueTypes)->toContain('obligation_balance_mismatch')
        ->and($issueTypes)->toContain('obligation_settlement_sum_mismatch')
        ->and($issueTypes)->toContain('settlement_box_log_mismatch')
        ->and($issueTypes)->toContain('operation_status_mismatch');
});

test('workflow report scoping limits non owners to their own operations', function (): void {
    $manager = actingAsWorkflowReportUser('manager');
    $otherUser = User::factory()->create(['is_active' => true]);
    $ownCustomer = workflowReportCustomer($manager, 'customer', 'Own Customer');
    $otherCustomer = workflowReportCustomer($otherUser, 'customer', 'Other Customer');
    $ownOperation = workflowReportOperation($manager, $ownCustomer);
    $otherOperation = workflowReportOperation($otherUser, $otherCustomer);

    OperationObligation::factory()->create([
        'operation_id' => $ownOperation->id,
        'counterparty_id' => $ownCustomer->id,
        'amount' => 100,
        'currency' => 'USD',
        'created_by' => $manager->id,
    ]);
    OperationObligation::factory()->create([
        'operation_id' => $otherOperation->id,
        'counterparty_id' => $otherCustomer->id,
        'amount' => 900,
        'currency' => 'USD',
        'created_by' => $otherUser->id,
    ]);

    $this->getJson('/api/v1/reports/obligations')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.rows.0.operation_id', $ownOperation->id);
});

test('workflow reconciliation accepts consistent settled obligations and box logs', function (): void {
    $owner = actingAsWorkflowReportUser();
    $customer = workflowReportCustomer($owner, 'customer', 'Customer');
    $box = Box::factory()->create(['currency' => 'USD', 'current_balance' => 1400]);
    $operation = workflowReportOperation($owner, $customer, null, [
        'status' => OperationStatus::Completed->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
    ]);
    $obligation = OperationObligation::factory()->settled()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $customer->id,
        'amount' => 1000,
        'currency' => 'USD',
        'settled_amount' => 1000,
        'balance_amount' => 0,
        'created_by' => $owner->id,
    ]);
    $settlement = OperationSettlement::factory()->create([
        'operation_id' => $operation->id,
        'operation_obligation_id' => $obligation->id,
        'counterparty_id' => $customer->id,
        'counterparty_role' => OperationCounterpartyRole::Customer->value,
        'direction' => OperationSettlementDirection::CashIn->value,
        'amount' => 1000,
        'currency' => 'USD',
        'box_id' => $box->id,
        'created_by' => $owner->id,
    ]);

    BoxBalanceLog::factory()->create([
        'box_id' => $box->id,
        'operation_id' => $operation->id,
        'operation_settlement_id' => $settlement->id,
        'operation_type' => BoxBalanceOperationType::Add->value,
        'amount' => 1000,
        'balance_before' => 400,
        'balance_after' => 1400,
        'created_by' => $owner->id,
    ]);

    $this->getJson('/api/v1/reports/reconciliation')
        ->assertOk()
        ->assertJsonPath('data.summary.total_issues', 0);
});

test('workflow reconciliation detects settlement obligation direction currency and counterparty mismatches', function (): void {
    $owner = actingAsWorkflowReportUser();
    $customer = workflowReportCustomer($owner, 'customer', 'Customer');
    $otherCustomer = workflowReportCustomer($owner, 'customer', 'Other Customer');
    $operation = workflowReportOperation($owner, $customer, null, [
        'status' => OperationStatus::Pending->value,
        'customer_settlement_status' => OperationCustomerSettlementStatus::Pending->value,
    ]);
    $obligation = OperationObligation::factory()->partiallySettled()->create([
        'operation_id' => $operation->id,
        'counterparty_id' => $customer->id,
        'counterparty_role' => OperationCounterpartyRole::Customer->value,
        'type' => OperationObligationType::Receivable->value,
        'amount' => 1000,
        'currency' => 'USD',
        'settled_amount' => 100,
        'balance_amount' => 900,
        'created_by' => $owner->id,
    ]);

    OperationSettlement::factory()->create([
        'operation_id' => $operation->id,
        'operation_obligation_id' => $obligation->id,
        'counterparty_id' => $otherCustomer->id,
        'counterparty_role' => OperationCounterpartyRole::Customer->value,
        'direction' => OperationSettlementDirection::CashOut->value,
        'amount' => 100,
        'currency' => 'EGP',
        'created_by' => $owner->id,
    ]);

    $issue = collect($this->getJson('/api/v1/reports/reconciliation')
        ->assertOk()
        ->json('data.issues'))
        ->firstWhere('type', 'settlement_obligation_mismatch');

    expect($issue)->not->toBeNull()
        ->and($issue['settlement_currency'])->toBe('EGP')
        ->and($issue['obligation_currency'])->toBe('USD')
        ->and($issue['settlement_direction'])->toBe(OperationSettlementDirection::CashOut->value)
        ->and($issue['expected_direction'])->toBe(OperationSettlementDirection::CashIn->value)
        ->and($issue['settlement_counterparty_id'])->toBe($otherCustomer->id)
        ->and($issue['obligation_counterparty_id'])->toBe($customer->id);
});

test('workflow report endpoints reject invalid financial filters', function (string $endpoint, string $query, string $field): void {
    actingAsWorkflowReportUser();

    $this->getJson("/api/v1/reports/{$endpoint}?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'invalid obligation type' => ['obligations', 'obligation_type=debt', 'obligation_type'],
    'invalid counterparty role' => ['obligations', 'counterparty_role=agent', 'counterparty_role'],
    'invalid obligation status' => ['obligations', 'obligation_status=done', 'obligation_status'],
    'oversized currency' => ['operations-workflow', 'currency=TOO-LONG-CURRENCY', 'currency'],
]);

test('new workflow report exports can be queued', function (string $type): void {
    Queue::fake();
    actingAsWorkflowReportUser();

    $this->postJson('/api/v1/reports/export', [
        'type' => $type,
        'format' => 'excel',
        'params' => [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ],
    ])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonStructure(['data' => ['job_id', 'status_url']]);

    Queue::assertPushed(GenerateReportJob::class);
    Queue::assertPushedTimes(GenerateReportJob::class, 1);
})->with([
    'obligations',
    'operation-obligations',
    'operations-workflow',
    'workflow-reconciliation',
    'reconciliation',
]);
