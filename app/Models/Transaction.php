<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'vault_id',
    'customer_id',
    'from_customer_id',
    'to_customer_id',
    'type',
    'amount',
    'currency_code',
    'exchange_rate',
    'usd_value',
    'commission_type',
    'commission_rate',
    'commission_sign',
    'commission_usd',
    'net_usd_value',
    'direction',
    'note',
    'reference_number',
    'country',
    'transaction_date',
])]
class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'commission_usd' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fromCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'from_customer_id');
    }

    public function toCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'to_customer_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'usd_value' => 'decimal:4',
            'commission_rate' => 'decimal:4',
            'commission_sign' => 'integer',
            'commission_usd' => 'decimal:4',
            'net_usd_value' => 'decimal:4',
            'direction' => 'integer',
            'transaction_date' => 'date',
        ];
    }
}
