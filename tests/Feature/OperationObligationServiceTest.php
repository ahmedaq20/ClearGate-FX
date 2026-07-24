<?php

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationSettlementDirection;
use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
use App\Models\User;
use App\Services\OperationObligationService;
use Illuminate\Validation\ValidationException;

test('it opens an idempotent customer receivable obligation', function (): void {
    $creator = User::factory()->create();
    $operation = Operation::factory()->create(['created_by' => $creator->id]);
    $customer = Customer::factory()->create(['type' => 'customer']);
    $service = app(OperationObligationService::class);

    $obligation = $service->openReceivable(
        operation: $operation,
        counterparty: $customer,
        creator: $creator,
        reason: OperationObligationReason::CustomerPrincipal,
        amount: 1000,
        currency: ' usd ',
        exchangeRate: 1
    );

    $sameObligation = $service->openReceivable(
        operation: $operation,
        counterparty: $customer,
        creator: $creator,
        reason: OperationObligationReason::CustomerPrincipal,
        amount: 1000,
        currency: 'USD',
        exchangeRate: 1
    );

    expect($sameObligation->is($obligation))->toBeTrue()
        ->and(OperationObligation::query()->count())->toBe(1)
        ->and($obligation->counterparty_role)->toBe(OperationCounterpartyRole::Customer)
        ->and($obligation->type)->toBe(OperationObligationType::Receivable)
        ->and($obligation->reason)->toBe(OperationObligationReason::CustomerPrincipal)
        ->and($obligation->status)->toBe(OperationObligationStatus::Open)
        ->and($obligation->currency)->toBe('USD')
        ->and((float) $obligation->amount)->toBe(1000.0)
        ->and((float) $obligation->settled_amount)->toBe(0.0)
        ->and((float) $obligation->balance_amount)->toBe(1000.0);
});

test('it rejects conflicting duplicate obligations', function (): void {
    $creator = User::factory()->create();
    $operation = Operation::factory()->create(['created_by' => $creator->id]);
    $customer = Customer::factory()->create(['type' => 'customer']);
    $service = app(OperationObligationService::class);

    $service->openReceivable(
        operation: $operation,
        counterparty: $customer,
        creator: $creator,
        reason: OperationObligationReason::CustomerPrincipal,
        amount: 1000,
        currency: 'USD',
        exchangeRate: 1
    );

    $service->openReceivable(
        operation: $operation,
        counterparty: $customer,
        creator: $creator,
        reason: OperationObligationReason::CustomerPrincipal,
        amount: 1200,
        currency: 'USD',
        exchangeRate: 1
    );
})->throws(ValidationException::class);

test('it opens supplier payable obligations', function (): void {
    $creator = User::factory()->create();
    $operation = Operation::factory()->create(['created_by' => $creator->id]);
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $service = app(OperationObligationService::class);

    $obligation = $service->openPayable(
        operation: $operation,
        counterparty: $supplier,
        creator: $creator,
        reason: OperationObligationReason::SupplierSettlement,
        amount: 50000,
        currency: 'EGP',
        exchangeRate: 50
    );

    expect($obligation->counterparty_role)->toBe(OperationCounterpartyRole::Supplier)
        ->and($obligation->type)->toBe(OperationObligationType::Payable)
        ->and($obligation->reason)->toBe(OperationObligationReason::SupplierSettlement)
        ->and($obligation->status)->toBe(OperationObligationStatus::Open)
        ->and($obligation->currency)->toBe('EGP')
        ->and((float) $obligation->amount)->toBe(50000.0)
        ->and((float) $obligation->exchange_rate)->toBe(50.0);
});

test('it partially and fully settles an obligation without moving cash balances', function (): void {
    $creator = User::factory()->create();
    $operation = Operation::factory()->create(['created_by' => $creator->id]);
    $supplier = Customer::factory()->create(['type' => 'supplier', 'balance_usd' => 1500]);
    $box = Box::factory()->create(['current_balance' => 2000, 'currency' => 'USD']);
    $vault = $creator->vault()->firstOrFail();
    $vaultStartingBalance = (float) $vault->balance_usd;
    $service = app(OperationObligationService::class);

    $obligation = $service->openPayable(
        operation: $operation,
        counterparty: $supplier,
        creator: $creator,
        reason: OperationObligationReason::SupplierSettlement,
        amount: 1000,
        currency: 'USD',
        exchangeRate: 1
    );

    $firstSettlement = $service->settle($obligation, $creator, [
        'amount' => 250,
        'currency' => 'USD',
        'direction' => OperationSettlementDirection::CashOut,
        'idempotency_key' => 'supplier-settlement-1',
    ]);

    expect($firstSettlement->direction)->toBe(OperationSettlementDirection::CashOut)
        ->and((float) $firstSettlement->amount)->toBe(250.0)
        ->and($obligation->refresh()->status)->toBe(OperationObligationStatus::PartiallySettled)
        ->and((float) $obligation->settled_amount)->toBe(250.0)
        ->and((float) $obligation->balance_amount)->toBe(750.0);

    $service->settle($obligation, $creator, [
        'amount' => 750,
        'currency' => 'USD',
        'direction' => 'cash_out',
        'idempotency_key' => 'supplier-settlement-2',
    ]);

    expect($obligation->refresh()->status)->toBe(OperationObligationStatus::Settled)
        ->and((float) $obligation->settled_amount)->toBe(1000.0)
        ->and((float) $obligation->balance_amount)->toBe(0.0)
        ->and(OperationSettlement::query()->count())->toBe(2)
        ->and(BoxBalanceLog::query()->count())->toBe(0)
        ->and((float) $box->refresh()->current_balance)->toBe(2000.0)
        ->and((float) $vault->refresh()->balance_usd)->toBe($vaultStartingBalance)
        ->and((float) $supplier->refresh()->balance_usd)->toBe(1500.0);
});

test('it rejects over settlement and currency mismatches', function (): void {
    $creator = User::factory()->create();
    $operation = Operation::factory()->create(['created_by' => $creator->id]);
    $customer = Customer::factory()->create(['type' => 'customer']);
    $service = app(OperationObligationService::class);

    $obligation = $service->openReceivable(
        operation: $operation,
        counterparty: $customer,
        creator: $creator,
        reason: OperationObligationReason::CustomerPrincipal,
        amount: 1000,
        currency: 'USD',
        exchangeRate: 1
    );

    expect(fn () => $service->settle($obligation, $creator, [
        'amount' => 1001,
        'currency' => 'USD',
        'direction' => OperationSettlementDirection::CashIn,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->settle($obligation, $creator, [
        'amount' => 500,
        'currency' => 'EGP',
        'direction' => OperationSettlementDirection::CashIn,
    ]))->toThrow(ValidationException::class);

    expect(OperationSettlement::query()->count())->toBe(0)
        ->and($obligation->refresh()->status)->toBe(OperationObligationStatus::Open)
        ->and((float) $obligation->balance_amount)->toBe(1000.0);
});

test('it makes settlement idempotency keys safe to retry', function (): void {
    $creator = User::factory()->create();
    $operation = Operation::factory()->create(['created_by' => $creator->id]);
    $supplier = Customer::factory()->create(['type' => 'supplier']);
    $service = app(OperationObligationService::class);

    $obligation = $service->openPayable(
        operation: $operation,
        counterparty: $supplier,
        creator: $creator,
        reason: OperationObligationReason::SupplierSettlement,
        amount: 1000,
        currency: 'USD',
        exchangeRate: 1
    );

    $settlement = $service->settle($obligation, $creator, [
        'amount' => 400,
        'currency' => 'USD',
        'direction' => OperationSettlementDirection::CashOut,
        'idempotency_key' => 'retry-safe-key',
    ]);

    $retriedSettlement = $service->settle($obligation, $creator, [
        'amount' => 400,
        'currency' => 'USD',
        'direction' => OperationSettlementDirection::CashOut,
        'idempotency_key' => 'retry-safe-key',
    ]);

    expect($retriedSettlement->is($settlement))->toBeTrue()
        ->and(OperationSettlement::query()->count())->toBe(1)
        ->and((float) $obligation->refresh()->settled_amount)->toBe(400.0)
        ->and((float) $obligation->balance_amount)->toBe(600.0);

    expect(fn () => $service->settle($obligation, $creator, [
        'amount' => 300,
        'currency' => 'USD',
        'direction' => OperationSettlementDirection::CashOut,
        'idempotency_key' => 'retry-safe-key',
    ]))->toThrow(ValidationException::class);
});
