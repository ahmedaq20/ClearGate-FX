<?php

namespace App\Models;

use Database\Factories\BoxAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

#[Fillable([
    'box_id',
    'adjustment_type',
    'amount',
    'balance_before',
    'balance_after',
    'reason',
    'notes',
    'created_by',
])]
class BoxAdjustment extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<BoxAdjustmentFactory> */
    use HasFactory;

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updated(function (BoxAdjustment $adjustment): void {
            AuditLog::record(
                action: 'box_adjustment.updated',
                model: $adjustment,
                userId: Auth::id(),
                oldValues: $adjustment->getOriginal(),
                newValues: $adjustment->getChanges()
            );
        });

        static::deleted(function (BoxAdjustment $adjustment): void {
            AuditLog::record(
                action: 'box_adjustment.deleted',
                model: $adjustment,
                userId: Auth::id(),
                oldValues: $adjustment->getOriginal()
            );
        });
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
            'created_at' => 'datetime',
        ];
    }
}
