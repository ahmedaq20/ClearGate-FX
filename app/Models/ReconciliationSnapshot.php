<?php

namespace App\Models;

use Database\Factories\ReconciliationSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'capital_balance',
    'boxes_total_balance',
    'free_capital',
    'difference',
    'status',
    'created_by',
])]
class ReconciliationSnapshot extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<ReconciliationSnapshotFactory> */
    use HasFactory;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capital_balance' => 'decimal:4',
            'boxes_total_balance' => 'decimal:4',
            'free_capital' => 'decimal:4',
            'difference' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }
}
