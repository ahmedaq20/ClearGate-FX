<?php

namespace App\Models;

use Database\Factories\CapitalAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'balance_usd',
])]
class CapitalAccount extends Model
{
    /** @use HasFactory<CapitalAccountFactory> */
    use HasFactory;

    protected $attributes = [
        'balance_usd' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CapitalTransaction::class);
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
            'balance_usd' => 'decimal:4',
        ];
    }
}
