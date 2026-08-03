<?php

use Illuminate\Support\Facades\Schema;

test('operation financial workflow schema is available', function (): void {
    foreach ([
        'customer_direction',
        'supplier_direction',
        'customer_settlement_status',
        'customer_settled_at',
        'supplier_fulfillment_status',
        'supplier_fulfilled_at',
        'supplier_settlement_status',
        'supplier_settled_at',
        'commission_currency',
    ] as $column) {
        expect(Schema::hasColumn('operations', $column))->toBeTrue();
    }

    expect(Schema::hasTable('operation_obligations'))->toBeTrue();

    foreach ([
        'operation_id',
        'counterparty_id',
        'counterparty_role',
        'type',
        'reason',
        'amount',
        'currency',
        'exchange_rate',
        'settled_amount',
        'balance_amount',
        'status',
        'created_by',
    ] as $column) {
        expect(Schema::hasColumn('operation_obligations', $column))->toBeTrue();
    }

    expect(Schema::hasTable('operation_settlements'))->toBeTrue();

    foreach ([
        'operation_id',
        'operation_obligation_id',
        'counterparty_id',
        'counterparty_role',
        'direction',
        'amount',
        'currency',
        'exchange_rate',
        'box_id',
        'vault_id',
        'settlement_date',
        'idempotency_key',
        'notes',
        'created_by',
    ] as $column) {
        expect(Schema::hasColumn('operation_settlements', $column))->toBeTrue();
    }

    expect(Schema::hasColumn('box_balance_logs', 'operation_settlement_id'))->toBeTrue()
        ->and(Schema::hasColumn('box_balance_logs', 'reason'))->toBeTrue();
});
