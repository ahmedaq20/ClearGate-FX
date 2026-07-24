<?php

namespace App\Models;

use Database\Factories\CapitalTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'capital_account_id',
    'user_id',
    'created_by',
    'updated_by',
    'box_id',
    'owner_expense_id',
    'type',
    'amount',
    'currency',
    'balance_before',
    'balance_after',
    'total_balance_before',
    'total_balance_after',
    'unallocated_balance_before',
    'unallocated_balance_after',
    'allocated_balance_before',
    'allocated_balance_after',
    'box_balance_before',
    'box_balance_after',
    'transaction_date',
    'transaction_at',
    'reference_number',
    'statement',
    'notes',
])]
class CapitalTransaction extends Model
{
    /** @use HasFactory<CapitalTransactionFactory> */
    use HasFactory, SoftDeletes;

    public function capitalAccount(): BelongsTo
    {
        return $this->belongsTo(CapitalAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function ownerExpense(): BelongsTo
    {
        return $this->belongsTo(OwnerExpense::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'total_balance_before' => 'decimal:4',
            'total_balance_after' => 'decimal:4',
            'unallocated_balance_before' => 'decimal:4',
            'unallocated_balance_after' => 'decimal:4',
            'allocated_balance_before' => 'decimal:4',
            'allocated_balance_after' => 'decimal:4',
            'box_balance_before' => 'decimal:4',
            'box_balance_after' => 'decimal:4',
            'transaction_date' => 'date',
            'transaction_at' => 'datetime',
        ];
    }
}
