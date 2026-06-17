<?php

namespace App\Models;

use App\Enums\BoxType;
use Database\Factories\BoxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'type',
    'current_balance',
    'currency',
    'assigned_user_id',
    'status',
    'notes',
])]
class Box extends Model
{
    /** @use HasFactory<BoxFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'current_balance' => 0,
        'status' => 'active',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function balanceLogs(): HasMany
    {
        return $this->hasMany(BoxBalanceLog::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BoxAdjustment::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BoxType::class,
            'current_balance' => 'decimal:4',
        ];
    }
}
