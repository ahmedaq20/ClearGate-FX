<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'name',
    'initial_balance',
    'balance_usd',
    'currency_code',
    'note',
    'is_active',
])]
class Vault extends Model
{
    use SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'initial_balance' => 0,
        'balance_usd' => 0,
        'currency_code' => 'USD',
        'is_active' => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:4',
            'balance_usd' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
