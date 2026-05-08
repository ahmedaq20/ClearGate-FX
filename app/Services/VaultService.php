<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Support\Facades\DB;

class VaultService
{
    public function recalculateBalance(Vault $vault): void
    {
        $transactionsNet = (float) Transaction::query()
            ->where('vault_id', $vault->id)
            ->sum(DB::raw('net_usd_value * direction'));

        $vault->update([
            'balance_usd' => round((float) $vault->initial_balance + $transactionsNet, 4),
        ]);
    }

    public function createForUser(User $user, float $initialBalance = 0): Vault
    {
        $vault = Vault::withTrashed()->firstOrNew(['user_id' => $user->id]);

        if ($vault->exists) {
            if ($vault->trashed()) {
                $vault->restore();
            }

            return $vault;
        }

        $vault->fill([
            'name' => "Vault {$user->name}",
            'initial_balance' => $initialBalance,
            'balance_usd' => $initialBalance,
            'currency_code' => 'USD',
            'is_active' => true,
        ]);
        $vault->save();

        return $vault;
    }
}
