<?php

namespace App\Services;

use App\Enums\BoxBalanceOperationType;
use App\Models\AuditLog;
use App\Models\Box;
use App\Models\BoxAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoxAdjustmentService
{
    /**
     * @param  array{adjustment_type: string, amount: mixed, reason: string, notes?: string|null}  $data
     */
    public function create(Box $box, User $user, array $data): BoxAdjustment
    {
        return DB::transaction(function () use ($box, $user, $data): BoxAdjustment {
            $lockedBox = Box::query()->whereKey($box->id)->lockForUpdate()->first();

            if ($lockedBox === null) {
                throw ValidationException::withMessages([
                    'box' => 'الصندوق غير موجود',
                ]);
            }

            $amount = round((float) $data['amount'], 4);
            $balanceBefore = round((float) $lockedBox->current_balance, 4);

            if ($data['adjustment_type'] === 'decrease' && $amount > $balanceBefore) {
                throw ValidationException::withMessages([
                    'amount' => 'لا يمكن خصم مبلغ أكبر من رصيد الصندوق',
                ]);
            }

            $balanceAfter = $data['adjustment_type'] === 'increase'
                ? round($balanceBefore + $amount, 4)
                : round($balanceBefore - $amount, 4);

            $lockedBox->update(['current_balance' => $balanceAfter]);

            $adjustment = BoxAdjustment::query()->create([
                'box_id' => $lockedBox->id,
                'adjustment_type' => $data['adjustment_type'],
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $lockedBox->balanceLogs()->create([
                'operation_type' => $data['adjustment_type'] === 'increase'
                    ? BoxBalanceOperationType::Add->value
                    : BoxBalanceOperationType::Subtract->value,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'notes' => "Box adjustment #{$adjustment->id}: {$adjustment->reason}",
                'created_by' => $user->id,
            ]);

            AuditLog::record(
                action: 'box_adjustment.created',
                model: $adjustment,
                userId: $user->id,
                oldValues: ['box_balance' => $balanceBefore],
                newValues: [
                    'box_balance' => $balanceAfter,
                    'adjustment_type' => $adjustment->adjustment_type,
                    'amount' => $adjustment->amount,
                    'reason' => $adjustment->reason,
                ]
            );

            return $adjustment->load(['box', 'creator']);
        }, attempts: 3);
    }
}
