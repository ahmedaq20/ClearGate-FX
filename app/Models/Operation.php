<?php

namespace App\Models;

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
    'customer_currency',
    'customer_amount',
    'customer_exchange_rate',
    'commission_type',
    'commission_rate',
    'commission_amount',
    'customer_net_amount',
    'notes',
    'created_by',
])]
class Operation extends Model
{
    /** @use HasFactory<OperationFactory> */
    use HasFactory;

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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'supplier_amount' => 'decimal:4',
            'supplier_exchange_rate' => 'decimal:8',
            'customer_amount' => 'decimal:4',
            'customer_exchange_rate' => 'decimal:8',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:4',
            'customer_net_amount' => 'decimal:4',
        ];
    }
}
