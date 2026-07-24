<?php

namespace App\Models;

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationSettlementDirection;
use Database\Factories\OperationSettlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
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
])]
class OperationSettlement extends Model
{
    /** @use HasFactory<OperationSettlementFactory> */
    use HasFactory;

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(OperationObligation::class, 'operation_obligation_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'counterparty_id');
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function boxBalanceLogs(): HasMany
    {
        return $this->hasMany(BoxBalanceLog::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counterparty_role' => OperationCounterpartyRole::class,
            'direction' => OperationSettlementDirection::class,
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'settlement_date' => 'date',
        ];
    }
}
