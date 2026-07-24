<?php

use App\Enums\BoxBalanceOperationType;
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

test('operation financial workflow models expose relationships and enum casts', function (): void {
    $creator = User::factory()->create();
    $operation = Operation::factory()->create(['created_by' => $creator->id]);
    $counterparty = Customer::factory()->create(['type' => 'supplier']);
    $box = Box::factory()->create(['currency' => 'USD']);
    $vault = $creator->vault()->firstOrFail();

    $obligation = OperationObligation::factory()
        ->payable()
        ->create([
            'operation_id' => $operation->id,
            'counterparty_id' => $counterparty->id,
            'amount' => 1000,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'settled_amount' => 250,
            'balance_amount' => 750,
            'status' => OperationObligationStatus::PartiallySettled->value,
            'created_by' => $creator->id,
        ]);

    $settlement = OperationSettlement::factory()
        ->forObligation($obligation)
        ->cashOut()
        ->fromBox($box)
        ->fromVault($vault)
        ->create([
            'amount' => 250,
            'created_by' => $creator->id,
        ]);

    $balanceLog = BoxBalanceLog::factory()->create([
        'box_id' => $box->id,
        'operation_id' => $operation->id,
        'operation_settlement_id' => $settlement->id,
        'operation_type' => BoxBalanceOperationType::Subtract->value,
        'reason' => 'supplier_settlement',
    ]);

    expect($obligation->counterparty_role)->toBe(OperationCounterpartyRole::Supplier)
        ->and($obligation->type)->toBe(OperationObligationType::Payable)
        ->and($obligation->reason)->toBe(OperationObligationReason::SupplierSettlement)
        ->and($obligation->status)->toBe(OperationObligationStatus::PartiallySettled)
        ->and($settlement->counterparty_role)->toBe(OperationCounterpartyRole::Supplier)
        ->and($settlement->direction)->toBe(OperationSettlementDirection::CashOut);

    expect($operation->obligations()->first()->is($obligation))->toBeTrue()
        ->and($operation->settlements()->first()->is($settlement))->toBeTrue()
        ->and($obligation->operation->is($operation))->toBeTrue()
        ->and($obligation->counterparty->is($counterparty))->toBeTrue()
        ->and($obligation->creator->is($creator))->toBeTrue()
        ->and($obligation->settlements()->first()->is($settlement))->toBeTrue()
        ->and($settlement->operation->is($operation))->toBeTrue()
        ->and($settlement->obligation->is($obligation))->toBeTrue()
        ->and($settlement->counterparty->is($counterparty))->toBeTrue()
        ->and($settlement->box->is($box))->toBeTrue()
        ->and($settlement->vault->is($vault))->toBeTrue()
        ->and($settlement->creator->is($creator))->toBeTrue()
        ->and($settlement->boxBalanceLogs()->first()->is($balanceLog))->toBeTrue()
        ->and($counterparty->operationObligations()->first()->is($obligation))->toBeTrue()
        ->and($counterparty->operationSettlements()->first()->is($settlement))->toBeTrue()
        ->and($box->operationSettlements()->first()->is($settlement))->toBeTrue()
        ->and($vault->operationSettlements()->first()->is($settlement))->toBeTrue()
        ->and($balanceLog->operationSettlement->is($settlement))->toBeTrue()
        ->and($creator->createdOperationObligations()->first()->is($obligation))->toBeTrue()
        ->and($creator->createdOperationSettlements()->first()->is($settlement))->toBeTrue();
});
