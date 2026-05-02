<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'archivable_type',
    'archivable_id',
    'archived_by',
    'reason',
    'snapshot',
])]
class Archive extends Model
{
    public const UPDATED_AT = null;

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }
}
