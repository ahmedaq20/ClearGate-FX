<?php

namespace App\Models;

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use Database\Factories\OperationObligationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
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
])]
class OperationObligation extends Model
{
    /** @use HasFactory<OperationObligationFactory> */
    use HasFactory;

    protected $attributes = [
        'settled_amount' => 0,
        'status' => 'open',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'counterparty_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(OperationSettlement::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counterparty_role' => OperationCounterpartyRole::class,
            'type' => OperationObligationType::class,
            'reason' => OperationObligationReason::class,
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'settled_amount' => 'decimal:4',
            'balance_amount' => 'decimal:4',
            'status' => OperationObligationStatus::class,
        ];
    }
}
