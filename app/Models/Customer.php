<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'vault_id',
    'customer_code',
    'name',
    'phone',
    'type',
    'note',
    'category',
    'balance_usd',
    'country',
    'is_active',
])]
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'customer',
        'category' => 'regular',
        'balance_usd' => 0,
        'is_active' => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }

    public function supplierOperations(): HasMany
    {
        return $this->hasMany(Operation::class, 'supplier_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'balance_usd' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
