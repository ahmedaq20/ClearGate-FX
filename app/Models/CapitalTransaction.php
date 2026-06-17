<?php

namespace App\Models;

use Database\Factories\CapitalTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'capital_account_id',
    'user_id',
    'box_id',
    'owner_expense_id',
    'type',
    'amount',
    'balance_before',
    'balance_after',
    'transaction_date',
    'notes',
])]
class CapitalTransaction extends Model
{
    /** @use HasFactory<CapitalTransactionFactory> */
    use HasFactory;

    public function capitalAccount(): BelongsTo
    {
        return $this->belongsTo(CapitalAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'transaction_date' => 'date',
        ];
    }
}
