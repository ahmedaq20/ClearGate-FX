<?php

namespace App\Models;

use Database\Factories\OwnerExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'capital_account_id',
    'user_id',
    'title',
    'category',
    'amount',
    'expense_date',
    'notes',
])]
class OwnerExpense extends Model
{
    /** @use HasFactory<OwnerExpenseFactory> */
    use HasFactory;

    public function capitalAccount(): BelongsTo
    {
        return $this->belongsTo(CapitalAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'expense_date' => 'date',
        ];
    }
}
