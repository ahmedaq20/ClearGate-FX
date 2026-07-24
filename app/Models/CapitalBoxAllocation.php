<?php

namespace App\Models;

use Database\Factories\CapitalBoxAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'capital_account_id',
    'box_id',
    'currency',
    'allocated_balance',
])]
class CapitalBoxAllocation extends Model
{
    /** @use HasFactory<CapitalBoxAllocationFactory> */
    use HasFactory;

    protected $attributes = [
        'allocated_balance' => 0,
    ];

    public function capitalAccount(): BelongsTo
    {
        return $this->belongsTo(CapitalAccount::class);
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allocated_balance' => 'decimal:4',
        ];
    }
}
