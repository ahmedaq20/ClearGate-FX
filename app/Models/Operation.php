<?php

namespace App\Models;

use App\Enums\OperationCustomerDirection;
use App\Enums\OperationCustomerSettlementStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierDirection;
use App\Enums\OperationSupplierFulfillmentStatus;
use App\Enums\OperationSupplierSettlementStatus;
use Database\Factories\OperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference_number',
    'transaction_date',
    'supplier_id',
    'box_id',
    'customer_id',
    'supplier_currency',
    'supplier_amount',
    'supplier_exchange_rate',
    'supplier_direction',
    'customer_currency',
    'customer_amount',
    'customer_exchange_rate',
    'commission_type',
    'commission_rate',
    'commission_amount',
    'customer_net_amount',
    'status',
    'customer_direction',
    'customer_settlement_status',
    'customer_settled_at',
    'supplier_fulfillment_status',
    'supplier_fulfilled_at',
    'supplier_settlement_status',
    'supplier_settled_at',
    'commission_currency',
    'completed_at',
    'cancelled_at',
    'cancellation_reason',
    'notes',
    'created_by',
])]
class Operation extends Model
{
    /** @use HasFactory<OperationFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'supplier_id');
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function boxBalanceLogs(): HasMany
    {
        return $this->hasMany(BoxBalanceLog::class);
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(OperationObligation::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(OperationSettlement::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'status' => OperationStatus::class,
            'supplier_amount' => 'decimal:4',
            'supplier_exchange_rate' => 'decimal:8',
            'supplier_direction' => OperationSupplierDirection::class,
            'customer_amount' => 'decimal:4',
            'customer_exchange_rate' => 'decimal:8',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:4',
            'customer_net_amount' => 'decimal:4',
            'customer_direction' => OperationCustomerDirection::class,
            'customer_settlement_status' => OperationCustomerSettlementStatus::class,
            'customer_settled_at' => 'datetime',
            'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::class,
            'supplier_fulfilled_at' => 'datetime',
            'supplier_settlement_status' => OperationSupplierSettlementStatus::class,
            'supplier_settled_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
