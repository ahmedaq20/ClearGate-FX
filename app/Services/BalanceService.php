<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Vault;

class BalanceService
{
    /**
     * @return array{initial_balance: float, total_receive: float, total_send: float, balance_usd: float}
     */
    public function getVaultBalance(int $vaultId): array
    {
        $vault = Vault::query()->findOrFail($vaultId);

        $totalReceive = (float) Transaction::query()
            ->where('vault_id', $vaultId)
            ->where('type', 'receive')
            ->sum('net_usd_value');

        $totalSend = (float) Transaction::query()
            ->where('vault_id', $vaultId)
            ->where('type', 'send')
            ->sum('net_usd_value');

        return [
            'initial_balance' => (float) $vault->initial_balance,
            'total_receive' => round($totalReceive, 4),
            'total_send' => round($totalSend, 4),
            'balance_usd' => (float) $vault->balance_usd,
        ];
    }

    public function getCustomerBalance(int $customerId): float
    {
        return (float) Customer::query()->findOrFail($customerId)->balance_usd;
    }

    /**
     * @return array{receive: float, send: float, net: float, count: int}
     */
    public function getDailyNet(int $userId, string $date): array
    {
        $receive = (float) Transaction::query()
            ->where('user_id', $userId)
            ->whereDate('transaction_date', $date)
            ->where('type', 'receive')
            ->sum('net_usd_value');

        $send = (float) Transaction::query()
            ->where('user_id', $userId)
            ->whereDate('transaction_date', $date)
            ->where('type', 'send')
            ->sum('net_usd_value');

        $count = Transaction::query()
            ->where('user_id', $userId)
            ->whereDate('transaction_date', $date)
            ->count();

        return [
            'receive' => round($receive, 4),
            'send' => round($send, 4),
            'net' => round($receive - $send, 4),
            'count' => $count,
        ];
    }

    /**
     * @return array{receive: float, send: float, net: float, count: int}
     */
    public function getMonthlySummary(int $year, int $month, ?int $userId = null): array
    {
        $query = Transaction::query()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $receive = (float) (clone $query)->where('type', 'receive')->sum('net_usd_value');
        $send = (float) (clone $query)->where('type', 'send')->sum('net_usd_value');

        return [
            'receive' => round($receive, 4),
            'send' => round($send, 4),
            'net' => round($receive - $send, 4),
            'count' => (clone $query)->count(),
        ];
    }
}
