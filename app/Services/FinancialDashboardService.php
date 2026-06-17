<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\OperationStatus;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinancialDashboardService
{
    public function __construct(private readonly ReconciliationService $reconciliationService) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function financial(array $filters, User $user): array
    {
        $operations = $this->filteredOperations($filters, $user);
        $todayOperations = $this->filteredOperations($filters, $user)
            ->whereDate('transaction_date', now()->toDateString());
        $reconciliation = $this->reconciliationService->calculate($this->ownerForDashboard($user));

        return [
            'capital_balance' => $reconciliation['capital_balance'],
            'free_capital' => $reconciliation['free_capital'],
            'total_boxes_balance' => $this->totalBoxesBalance($filters, $user),
            'reconciliation_status' => $reconciliation['status'],
            'reconciliation_difference' => $reconciliation['difference'],
            'pending_operations_count' => (clone $operations)->where('status', OperationStatus::Pending->value)->count(),
            'pending_operations_amount' => $this->sumOperationAmount((clone $operations)->where('status', OperationStatus::Pending->value)),
            'completed_operations_count' => (clone $operations)->where('status', OperationStatus::Completed->value)->count(),
            'completed_operations_amount' => $this->sumOperationAmount((clone $operations)->where('status', OperationStatus::Completed->value)),
            'today_operations_count' => (clone $todayOperations)->count(),
            'today_operations_amount' => $this->sumOperationAmount(clone $todayOperations),
            'today_commissions' => $this->sumCommissions(clone $todayOperations),
            'suppliers_count' => $this->suppliersQuery($filters, $user)->count(),
            'customers_count' => $this->customersQuery($user)->count(),
            'boxes_count' => $this->boxesQuery($filters, $user)->count(),
            'top_pending_operations' => $this->pendingOperations($filters, $user),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function suppliers(array $filters, User $user): array
    {
        $operations = $this->filteredOperations($filters, $user)->whereNotNull('supplier_id');
        $pendingSupplierIds = (clone $operations)
            ->where('status', OperationStatus::Pending->value)
            ->distinct()
            ->count('supplier_id');

        return [
            'total_suppliers' => $this->suppliersQuery($filters, $user)->count(),
            'suppliers_with_pending_operations' => $pendingSupplierIds,
            'top_suppliers_by_volume' => $this->topSuppliers($filters, $user, 'supplier_amount'),
            'top_suppliers_by_commission' => $this->topSuppliers($filters, $user, 'commission_amount'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function boxes(array $filters, User $user): array
    {
        return $this->boxesQuery($filters, $user)
            ->withMax('balanceLogs', 'created_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Box $box): array => [
                'id' => $box->id,
                'name' => $box->name,
                'type' => $box->type?->value ?? $box->type,
                'current_balance' => round((float) $box->current_balance, 4),
                'last_activity_date' => $box->balance_logs_max_created_at === null
                    ? null
                    : Carbon::parse($box->balance_logs_max_created_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, float>
     */
    public function commissions(array $filters, User $user): array
    {
        return [
            'today_commissions' => $this->sumCommissions(
                $this->filteredOperations($filters, $user)->whereDate('transaction_date', now()->toDateString())
            ),
            'monthly_commissions' => $this->sumCommissions(
                $this->filteredOperations($filters, $user)
                    ->whereYear('transaction_date', now()->year)
                    ->whereMonth('transaction_date', now()->month)
            ),
            'yearly_commissions' => $this->sumCommissions(
                $this->filteredOperations($filters, $user)->whereYear('transaction_date', now()->year)
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function charts(array $filters, User $user): array
    {
        $operations = $this->operationsByDay($filters, $user);
        $commissions = $this->commissionsByDay($filters, $user);
        $pending = $this->filteredOperations($filters, $user)->where('status', OperationStatus::Pending->value);
        $completed = $this->filteredOperations($filters, $user)->where('status', OperationStatus::Completed->value);

        return [
            'operations_by_day' => $operations,
            'commissions_by_day' => $commissions,
            'pending_vs_completed' => [
                'pending' => [
                    'count' => (clone $pending)->count(),
                    'amount' => $this->sumOperationAmount(clone $pending),
                ],
                'completed' => [
                    'count' => (clone $completed)->count(),
                    'amount' => $this->sumOperationAmount(clone $completed),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function pendingOperations(array $filters, User $user, int $limit = 10): array
    {
        return $this->filteredOperations($filters, $user)
            ->with(['supplier:id,name', 'customer:id,name'])
            ->where('status', OperationStatus::Pending->value)
            ->oldest('transaction_date')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Operation $operation): array => [
                'reference_number' => $operation->reference_number,
                'supplier' => $operation->supplier?->name,
                'customer' => $operation->customer?->name,
                'amount' => round((float) ($operation->supplier_amount ?? $operation->customer_amount), 4),
                'transaction_date' => $operation->transaction_date?->toDateString(),
                'pending_days' => $operation->transaction_date?->startOfDay()->diffInDays(now()->startOfDay()) ?? 0,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Operation>
     */
    private function filteredOperations(array $filters, User $user): Builder
    {
        $query = Operation::query();

        if (! $this->hasFullDashboardAccess($user)) {
            $query->where('created_by', $user->id);
        }

        return $query
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('transaction_date', '<=', $date))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, int $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['box_id'] ?? null, fn (Builder $query, int $boxId) => $query->where('box_id', $boxId));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Box>
     */
    private function boxesQuery(array $filters, User $user): Builder
    {
        $query = Box::query();

        if (! $this->hasFullDashboardAccess($user)) {
            $query->where('assigned_user_id', $user->id);
        }

        return $query->when($filters['box_id'] ?? null, fn (Builder $query, int $boxId) => $query->whereKey($boxId));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function totalBoxesBalance(array $filters, User $user): float
    {
        return round((float) $this->boxesQuery($filters, $user)->sum('current_balance'), 4);
    }

    /**
     * @return Builder<Customer>
     */
    private function customersQuery(User $user): Builder
    {
        $query = Customer::query()->where('type', CustomerType::Customer->value);

        if (! $this->hasFullDashboardAccess($user)) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    private function suppliersQuery(array $filters, User $user): Builder
    {
        $query = Customer::query()->where('type', CustomerType::Supplier->value);

        if (! $this->hasFullDashboardAccess($user)) {
            $query->where('user_id', $user->id);
        }

        return $query->when($filters['supplier_id'] ?? null, fn (Builder $query, int $supplierId) => $query->whereKey($supplierId));
    }

    /**
     * @param  Builder<Operation>  $query
     */
    private function sumOperationAmount(Builder $query): float
    {
        return round((float) $query->sum('customer_amount'), 4);
    }

    /**
     * @param  Builder<Operation>  $query
     */
    private function sumCommissions(Builder $query): float
    {
        return round((float) $query->sum('commission_amount'), 4);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function topSuppliers(array $filters, User $user, string $sumColumn): array
    {
        $customersTable = (new Customer)->getTable();
        $operationsTable = (new Operation)->getTable();

        return $this->filteredOperations($filters, $user)
            ->join($customersTable, "{$customersTable}.id", '=', "{$operationsTable}.supplier_id")
            ->whereNotNull("{$operationsTable}.supplier_id")
            ->selectRaw("{$operationsTable}.supplier_id as id")
            ->selectRaw("{$customersTable}.name as name")
            ->selectRaw("SUM({$operationsTable}.{$sumColumn}) as total")
            ->groupBy("{$operationsTable}.supplier_id", "{$customersTable}.name")
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($supplier): array => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'total' => round((float) $supplier->total, 4),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function operationsByDay(array $filters, User $user): array
    {
        return $this->dailySeries(
            $this->filteredOperations($filters, $user)
                ->selectRaw('DATE(transaction_date) as date')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('SUM(customer_amount) as amount')
                ->groupByRaw('DATE(transaction_date)')
                ->orderBy('date')
                ->get(),
            'amount'
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function commissionsByDay(array $filters, User $user): array
    {
        return $this->dailySeries(
            $this->filteredOperations($filters, $user)
                ->selectRaw('DATE(transaction_date) as date')
                ->selectRaw('SUM(commission_amount) as amount')
                ->groupByRaw('DATE(transaction_date)')
                ->orderBy('date')
                ->get(),
            'amount'
        );
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function dailySeries(Collection $rows, string $amountKey): array
    {
        return $rows
            ->map(fn ($row): array => [
                'date' => Carbon::parse($row->date)->toDateString(),
                'count' => isset($row->count) ? (int) $row->count : null,
                $amountKey => round((float) $row->{$amountKey}, 4),
            ])
            ->all();
    }

    private function hasFullDashboardAccess(User $user): bool
    {
        return $user->isOwner() || $user->hasRole('admin', 'sanctum');
    }

    private function ownerForDashboard(User $user): User
    {
        if ($user->isOwner()) {
            return $user;
        }

        return User::role('owner', 'sanctum')->oldest('id')->first() ?? $user;
    }
}
