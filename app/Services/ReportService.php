<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReportService
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function generate(string $type, array $params, User $user): array
    {
        return match ($type) {
            'daily' => $this->daily($params, $user),
            'monthly' => $this->monthly($params, $user),
            'statement' => $this->statement($params, $user),
            'comparison' => $this->comparison($params, $user),
            default => throw ValidationException::withMessages([
                'type' => 'Unsupported report type.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function daily(array $params, User $user): array
    {
        $date = Carbon::parse($params['date'] ?? now())->toDateString();
        $userId = $this->resolveUserId($params, $user);
        $query = $this->transactionsQuery($user, $userId)->whereDate('transaction_date', $date);

        return [
            'type' => 'daily',
            'title' => 'التقرير اليومي',
            'date' => $date,
            'user' => $userId ? User::query()->find($userId) : null,
            'totals' => $this->totals($query),
            'by_currency' => $this->currencyTotals($query),
            'transactions' => (clone $query)->orderBy('transaction_date')->orderBy('id')->get(),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function monthly(array $params, User $user): array
    {
        $year = (int) ($params['year'] ?? now()->year);
        $month = (int) ($params['month'] ?? now()->month);
        $userId = $this->resolveUserId($params, $user);
        $startDate = Carbon::create($year, $month)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        $query = $this->transactionsQuery($user, $userId)
            ->whereBetween('transaction_date', [$startDate->toDateString(), $endDate->toDateString()]);

        return [
            'type' => 'monthly',
            'title' => 'التقرير الشهري',
            'month' => $month,
            'year' => $year,
            'date_from' => $startDate->toDateString(),
            'date_to' => $endDate->toDateString(),
            'user' => $userId ? User::query()->find($userId) : null,
            'totals' => $this->totals($query),
            'daily_totals' => $this->dailyTotals($query),
            'by_currency' => $this->currencyTotals($query),
            'transactions' => (clone $query)->orderBy('transaction_date')->orderBy('id')->get(),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function statement(array $params, User $user): array
    {
        if (! isset($params['customer_id'])) {
            throw ValidationException::withMessages([
                'params.customer_id' => 'Customer ID is required for statement reports.',
            ]);
        }

        $customer = Customer::withTrashed()->with(['user', 'vault'])->findOrFail((int) $params['customer_id']);

        if (! $user->isOwner() && $customer->user_id !== $user->id) {
            throw new AuthorizationException('غير مصرح');
        }

        $dateFrom = Carbon::parse($params['date_from'] ?? now()->startOfMonth())->toDateString();
        $dateTo = Carbon::parse($params['date_to'] ?? now())->toDateString();
        $query = Transaction::withTrashed()
            ->with(['user', 'vault', 'customer', 'currency'])
            ->where('customer_id', $customer->id)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo]);

        $openingBalance = $this->customerOpeningBalance($customer->id, $dateFrom);
        $totals = $this->totals($query);

        return [
            'type' => 'statement',
            'title' => 'كشف حساب عميل',
            'customer' => $customer,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'opening_balance_usd' => $openingBalance,
            'closing_balance_usd' => round($openingBalance + (float) $totals['net'], 4),
            'current_balance_usd' => (float) $customer->balance_usd,
            'totals' => $totals,
            'transactions' => (clone $query)->orderBy('transaction_date')->orderBy('id')->get(),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function comparison(array $params, User $user): array
    {
        if (! $user->isOwner()) {
            throw new AuthorizationException('غير مصرح');
        }

        $dateFrom = Carbon::parse($params['date_from'] ?? now()->startOfMonth())->toDateString();
        $dateTo = Carbon::parse($params['date_to'] ?? now())->toDateString();

        $rows = Transaction::withTrashed()
            ->selectRaw('users.id as user_id, users.name as user_name')
            ->selectRaw("SUM(CASE WHEN transactions.type = 'receive' THEN transactions.net_usd_value ELSE 0 END) as receive")
            ->selectRaw("SUM(CASE WHEN transactions.type = 'send' THEN transactions.net_usd_value ELSE 0 END) as send")
            ->selectRaw('COUNT(*) as count')
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->groupBy('users.id', 'users.name')
            ->orderBy('users.name')
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'user_name' => $row->user_name,
                'receive' => round((float) $row->receive, 4),
                'send' => round((float) $row->send, 4),
                'net' => round((float) $row->receive - (float) $row->send, 4),
                'count' => (int) $row->count,
            ])
            ->values();

        return [
            'type' => 'comparison',
            'title' => 'مقارنة المستخدمين',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'rows' => $rows,
            'totals' => [
                'receive' => round((float) $rows->sum('receive'), 4),
                'send' => round((float) $rows->sum('send'), 4),
                'net' => round((float) $rows->sum('net'), 4),
                'count' => (int) $rows->sum('count'),
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return array{receive: float, send: float, net: float, count: int}
     */
    private function totals(Builder $query): array
    {
        $receive = (float) (clone $query)->where('type', 'receive')->sum('net_usd_value');
        $send = (float) (clone $query)->where('type', 'send')->sum('net_usd_value');

        return [
            'receive' => round($receive, 4),
            'send' => round($send, 4),
            'net' => round($receive - $send, 4),
            'count' => (clone $query)->count(),
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return list<array{currency_code: string, receive: float, send: float, net: float, count: int}>
     */
    private function currencyTotals(Builder $query): array
    {
        return (clone $query)
            ->selectRaw('currency_code')
            ->selectRaw("SUM(CASE WHEN type = 'receive' THEN net_usd_value ELSE 0 END) as receive")
            ->selectRaw("SUM(CASE WHEN type = 'send' THEN net_usd_value ELSE 0 END) as send")
            ->selectRaw('COUNT(*) as count')
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get()
            ->map(fn ($row): array => [
                'currency_code' => $row->currency_code,
                'receive' => round((float) $row->receive, 4),
                'send' => round((float) $row->send, 4),
                'net' => round((float) $row->receive - (float) $row->send, 4),
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return list<array{date: string, receive: float, send: float, net: float, count: int}>
     */
    private function dailyTotals(Builder $query): array
    {
        return (clone $query)
            ->selectRaw('transaction_date')
            ->selectRaw("SUM(CASE WHEN type = 'receive' THEN net_usd_value ELSE 0 END) as receive")
            ->selectRaw("SUM(CASE WHEN type = 'send' THEN net_usd_value ELSE 0 END) as send")
            ->selectRaw('COUNT(*) as count')
            ->groupBy('transaction_date')
            ->orderBy('transaction_date')
            ->get()
            ->map(fn ($row): array => [
                'date' => Carbon::parse($row->transaction_date)->toDateString(),
                'receive' => round((float) $row->receive, 4),
                'send' => round((float) $row->send, 4),
                'net' => round((float) $row->receive - (float) $row->send, 4),
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactionsQuery(User $user, ?int $userId): Builder
    {
        $query = Transaction::withTrashed()->with(['user', 'vault', 'customer', 'currency']);

        if ($user->isOwner()) {
            return $userId ? $query->where('user_id', $userId) : $query;
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function resolveUserId(array $params, User $user): ?int
    {
        if (! $user->isOwner()) {
            return $user->id;
        }

        return isset($params['user_id']) ? (int) $params['user_id'] : null;
    }

    private function customerOpeningBalance(int $customerId, string $dateFrom): float
    {
        $receive = (float) Transaction::withTrashed()
            ->where('customer_id', $customerId)
            ->whereDate('transaction_date', '<', $dateFrom)
            ->where('type', 'receive')
            ->sum('net_usd_value');

        $send = (float) Transaction::withTrashed()
            ->where('customer_id', $customerId)
            ->whereDate('transaction_date', '<', $dateFrom)
            ->where('type', 'send')
            ->sum('net_usd_value');

        return round($receive - $send, 4);
    }
}
