<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Box;
use App\Models\CapitalAccount;
use App\Models\ReconciliationSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function __construct(private readonly CapitalService $capitalService) {}

    /**
     * @return array{capital_balance: float, boxes_total_balance: float, free_capital: float, difference: float, status: string}
     */
    public function calculate(User $owner): array
    {
        $account = $this->capitalService->account($owner);
        $boxesTotalBalance = round((float) Box::query()->sum('current_balance'), 4);
        $capitalBalance = round((float) $account->balance_usd, 4);
        $freeCapital = round((float) $account->free_balance_usd, 4);
        $difference = round($capitalBalance - ($boxesTotalBalance + $freeCapital), 4);

        return [
            'capital_balance' => $capitalBalance,
            'boxes_total_balance' => $boxesTotalBalance,
            'free_capital' => $freeCapital,
            'difference' => $difference,
            'status' => abs($difference) < 0.0001 ? 'balanced' : 'mismatch',
        ];
    }

    public function run(User $owner, User $createdBy): ReconciliationSnapshot
    {
        return DB::transaction(function () use ($owner, $createdBy): ReconciliationSnapshot {
            $snapshot = ReconciliationSnapshot::query()->create([
                ...$this->calculate($owner),
                'created_by' => $createdBy->id,
            ]);

            AuditLog::record(
                action: 'reconciliation.executed',
                model: $snapshot,
                userId: $createdBy->id,
                newValues: $snapshot->only([
                    'capital_balance',
                    'boxes_total_balance',
                    'free_capital',
                    'difference',
                    'status',
                ])
            );

            return $snapshot;
        }, attempts: 3);
    }

    public function latestOwnerAccount(): ?CapitalAccount
    {
        return CapitalAccount::query()->oldest('id')->first();
    }
}
