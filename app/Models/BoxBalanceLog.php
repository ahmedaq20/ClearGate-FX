<?php

namespace App\Models;

use App\Enums\BoxBalanceOperationType;
use Database\Factories\BoxBalanceLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'box_id',
    'operation_id',
    'operation_type',
    'amount',
    'balance_before',
    'balance_after',
    'notes',
    'created_by',
])]
class BoxBalanceLog extends Model
{
    /** @use HasFactory<BoxBalanceLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation_type' => BoxBalanceOperationType::class,
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }
}
