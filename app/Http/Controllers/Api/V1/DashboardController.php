<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OperationStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Dashboard\DashboardFilterRequest;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vault;
use App\Services\BalanceService;
use App\Services\FinancialDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @group Dashboard
 *
 * Operational summaries and charts for the authenticated user's permitted scope.
 */
class DashboardController extends BaseApiController
{
    public function __construct(
        private BalanceService $balanceService,
        private FinancialDashboardService $financialDashboardService,
    ) {}

    /**
     * Dashboard summary
     *
     * Return headline balances, today's net activity, customer counts, recent transactions, and top customers.
     * Owners receive the total balance across all vaults; managers receive data scoped to their own vault and customers.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"total_balance_usd":15000.5,"my_vault_balance":4200,"today_net_usd":{"receive":500,"send":200,"net":300,"count":3},"customers_count":18,"transactions_today_count":3,"recent_transactions":[],"top_customers":[]}}
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $isOwner = $this->isOwner($user);
        $today = now()->toDateString();

        $transactions = Transaction::query()->with(['customer', 'currency'])->latest()->limit(10);
        $customers = Customer::query();
        $operations = Operation::query();

        if (! $isOwner) {
            $transactions->where('user_id', $user->id);
            $customers->where('user_id', $user->id);
            $operations->where('created_by', $user->id);
        }

        $totalsQuery = Transaction::query();
        if (! $isOwner) {
            $totalsQuery->where('user_id', $user->id);
        }
        $totalReceive = round((float) (clone $totalsQuery)->where('type', 'receive')->sum('net_usd_value'), 4);
        $totalSend = round((float) (clone $totalsQuery)->where('type', 'send')->sum('net_usd_value'), 4);

        return $this->sendResponse([
            'total_balance_usd' => $isOwner ? (float) Vault::query()->sum('balance_usd') : null,
            'my_vault_balance' => (float) $user->vault()->value('balance_usd'),
            'today_net_usd' => $this->balanceService->getDailyNet($isOwner ? null : $user->id, $today),
            'customers_count' => (clone $customers)->count(),
            'transactions_today_count' => (clone $transactions)->whereDate('transaction_date', $today)->count(),
            'pending_operations_count' => (clone $operations)->where('status', OperationStatus::Pending->value)->count(),
            'completed_operations_count' => (clone $operations)->where('status', OperationStatus::Completed->value)->count(),
            'cancelled_operations_count' => (clone $operations)->where('status', OperationStatus::Cancelled->value)->count(),
            'pending_amount_total' => round((float) (clone $operations)->where('status', OperationStatus::Pending->value)->sum('customer_amount'), 4),
            'recent_transactions' => $transactions->get(),
            'top_customers' => $customers->orderByDesc('balance_usd')->limit(5)->get(),
            'total_summary' => [
                'receive' => $totalReceive,
                'send' => $totalSend,
                'net' => round($totalReceive - $totalSend, 4),
                'count' => (clone $totalsQuery)->count(),
            ],
        ]);
    }

    /**
     * Dashboard chart
     *
     * Return daily receive, send, and net totals for a chart period.
     *
     * @authenticated
     *
     * @queryParam period string Chart period: 7d, 30d, or 3m. Example: 7d
     *
     * @response 200 {"success":true,"message":"Success","data":{"labels":["2026-05-01","2026-05-02"],"receive":[100,250],"send":[50,75],"net":[50,175]}}
     */
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

    public function financial(DashboardFilterRequest $request): JsonResponse
    {
        if (! $this->canViewFinancialDashboard($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            $this->financialDashboardService->financial($request->validated(), $this->currentUser($request))
        );
    }

    public function suppliers(DashboardFilterRequest $request): JsonResponse
    {
        if (! $this->canViewFinancialDashboard($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            $this->financialDashboardService->suppliers($request->validated(), $this->currentUser($request))
        );
    }

    public function boxes(DashboardFilterRequest $request): JsonResponse
    {
        if (! $this->canViewFinancialDashboard($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            $this->financialDashboardService->boxes($request->validated(), $this->currentUser($request))
        );
    }

    public function commissions(DashboardFilterRequest $request): JsonResponse
    {
        if (! $this->canViewFinancialDashboard($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            $this->financialDashboardService->commissions($request->validated(), $this->currentUser($request))
        );
    }

    public function charts(DashboardFilterRequest $request): JsonResponse
    {
        if (! $this->canViewFinancialDashboard($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            $this->financialDashboardService->charts($request->validated(), $this->currentUser($request))
        );
    }

    private function canViewFinancialDashboard(?User $user): bool
    {
        return $user !== null && (
            $user->isOwner()
            || $user->hasRole('admin', 'sanctum')
            || $user->can('dashboard.viewFinancial')
        );
    }
}
