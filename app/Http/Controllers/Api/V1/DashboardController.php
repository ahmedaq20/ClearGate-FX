<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Vault;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends BaseApiController
{
    public function __construct(
        private BalanceService $balanceService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $isOwner = $this->isOwner($user);
        $today = now()->toDateString();

        $transactions = Transaction::query()->with(['customer', 'currency'])->latest()->limit(10);
        $customers = Customer::query();

        if (! $isOwner) {
            $transactions->where('user_id', $user->id);
            $customers->where('user_id', $user->id);
        }

        return $this->sendResponse([
            'total_balance_usd' => $isOwner ? (float) Vault::query()->sum('balance_usd') : null,
            'my_vault_balance' => (float) $user->vault()->value('balance_usd'),
            'today_net_usd' => $this->balanceService->getDailyNet($user->id, $today),
            'customers_count' => (clone $customers)->count(),
            'transactions_today_count' => (clone $transactions)->whereDate('transaction_date', $today)->count(),
            'recent_transactions' => $transactions->get(),
            'top_customers' => $customers->orderByDesc('balance_usd')->limit(5)->get(),
        ]);
    }

    public function chart(Request $request): JsonResponse
    {
        $period = $request->string('period', '7d')->toString();
        $startDate = match ($period) {
            '30d' => now()->subDays(29),
            '3m' => now()->subMonths(3)->startOfDay(),
            default => now()->subDays(6),
        };

        $query = Transaction::query()
            ->selectRaw('transaction_date')
            ->selectRaw("SUM(CASE WHEN type = 'receive' THEN net_usd_value ELSE 0 END) as receive")
            ->selectRaw("SUM(CASE WHEN type = 'send' THEN net_usd_value ELSE 0 END) as send")
            ->whereDate('transaction_date', '>=', $startDate->toDateString())
            ->groupBy('transaction_date')
            ->orderBy('transaction_date');

        if (! $this->isOwner($request->user())) {
            $query->where('user_id', $request->user()?->id);
        }

        $rows = $query->get()->keyBy(fn ($row) => Carbon::parse($row->transaction_date)->toDateString());
        $labels = [];
        $receive = [];
        $send = [];
        $net = [];

        for ($date = $startDate->copy(); $date->lte(now()); $date->addDay()) {
            $key = $date->toDateString();
            $row = $rows->get($key);
            $receiveValue = round((float) ($row?->receive ?? 0), 4);
            $sendValue = round((float) ($row?->send ?? 0), 4);

            $labels[] = $key;
            $receive[] = $receiveValue;
            $send[] = $sendValue;
            $net[] = round($receiveValue - $sendValue, 4);
        }

        return $this->sendResponse(compact('labels', 'receive', 'send', 'net'));
    }
}
