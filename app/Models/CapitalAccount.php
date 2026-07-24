<?php

namespace App\Models;

use App\Enums\CapitalAccountType;
use Database\Factories\CapitalAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'name',
    'type',
    'currency',
    'total_balance',
    'unallocated_balance',
    'allocated_balance',
    'balance_usd',
    'free_balance_usd',
    'notes',
])]
class CapitalAccount extends Model
{
    /** @use HasFactory<CapitalAccountFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'type' => 'owner',
        'currency' => 'USD',
        'total_balance' => 0,
        'unallocated_balance' => 0,
        'allocated_balance' => 0,
        'balance_usd' => 0,
        'free_balance_usd' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CapitalTransaction::class);
    }

    public function boxAllocations(): HasMany
    {
        return $this->hasMany(CapitalBoxAllocation::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OwnerExpense::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CapitalAccountType::class,
            'total_balance' => 'decimal:4',
            'unallocated_balance' => 'decimal:4',
            'allocated_balance' => 'decimal:4',
            'balance_usd' => 'decimal:4',
            'free_balance_usd' => 'decimal:4',
        ];
    }
}
