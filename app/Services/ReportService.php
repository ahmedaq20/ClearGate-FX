<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            'operations', 'daily', 'monthly' => $this->operations($params, $user),
            'commissions', 'daily-profit', 'monthly-profit' => $this->commissions($params, $user),
            'profit-summary' => $this->profitSummary($params, $user),
            'suppliers', 'profit-by-supplier' => $this->suppliers($params, $user),
            'customers', 'statement' => $this->customers($params, $user),
            'boxes' => $this->boxes($params, $user),
            'pending' => $this->pending($params, $user),
            'cancelled' => $this->cancelled($params, $user),
            'comparison', 'profit-by-user' => $this->comparison($params, $user),
            default => throw ValidationException::withMessages([
                'type' => 'نوع التقرير غير مدعوم.',
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

        return $this->operations(array_merge($params, [
            'date_from' => $date,
            'date_to' => $date,
        ]), $user);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function monthly(array $params, User $user): array
    {
        $year = (int) ($params['year'] ?? now()->year);
        $month = (int) ($params['month'] ?? now()->month);
        $startDate = Carbon::create($year, $month)->startOfMonth();

        return $this->operations(array_merge($params, [
            'date_from' => $startDate->toDateString(),
            'date_to' => $startDate->copy()->endOfMonth()->toDateString(),
        ]), $user);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function operations(array $params, User $user): array
    {
        $query = $this->operationsQuery($params, $user);
        $statusCounts = $this->statusCounts($query);

        $report = [
            'type' => 'operations',
            'title' => 'تقرير العمليات',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'total_operations' => (int) array_sum($statusCounts),
            'completed' => $statusCounts[OperationStatus::Completed->value],
            'pending' => $statusCounts[OperationStatus::Pending->value],
            'cancelled' => $statusCounts[OperationStatus::Cancelled->value],
            'total_transferred_amount' => $this->sumTransferredAmount(clone $query),
            'generated_at' => now(),
        ];

        if ($this->truthy($params['group_by_status'] ?? false)) {
            $report['by_status'] = $this->byStatus($query);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function commissions(array $params, User $user): array
    {
        $filters = $this->periodFilters($params);
        $query = $this->completedOperationsQuery($filters, $user);
        $count = (clone $query)->count();
        $total = $this->sumCommissionUsd(clone $query);

        return [
            'type' => 'commissions',
            'title' => 'تقرير العمولات',
            'currency' => 'USD',
            'period' => $filters['period'] ?? 'custom',
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'total_commission' => $total,
            'average_commission' => $count > 0 ? round($total / $count, 4) : 0.0,
            'operation_count' => $count,
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function dailyProfit(array $params, User $user): array
    {
        $rows = $this->completedOperationsQuery($params, $user)
            ->selectRaw('transaction_date as date')
            ->selectRaw('COUNT(*) as operations_count')
            ->selectRaw('SUM(commission_amount) as total_profit_usd')
            ->groupBy('transaction_date')
            ->orderBy('transaction_date')
            ->get()
            ->map(fn ($row): array => [
                'date' => Carbon::parse($row->date)->toDateString(),
                'operations_count' => (int) $row->operations_count,
                'total_profit_usd' => round((float) $row->total_profit_usd, 4),
            ])
            ->values()
            ->all();

        return [
            'type' => 'daily-profit',
            'title' => 'الأرباح اليومية',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'rows' => $rows,
            'total_profit_usd' => round((float) collect($rows)->sum('total_profit_usd'), 4),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function monthlyProfit(array $params, User $user): array
    {
        $rows = $this->completedOperationsQuery($params, $user)
            ->get(['transaction_date', 'commission_amount'])
            ->groupBy(fn (Operation $operation): string => $operation->transaction_date->format('Y-m'))
            ->map(fn (Collection $operations, string $month): array => [
                'month' => $month,
                'operations_count' => $operations->count(),
                'total_profit_usd' => round((float) $operations->sum('commission_amount'), 4),
            ])
            ->sortBy('month')
            ->values()
            ->all();

        return [
            'type' => 'monthly-profit',
            'title' => 'الأرباح الشهرية',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'rows' => $rows,
            'total_profit_usd' => round((float) collect($rows)->sum('total_profit_usd'), 4),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function profitSummary(array $params, User $user): array
    {
        $query = $this->operationsQuery($params, $user);

        return [
            'type' => 'profit-summary',
            'title' => 'ملخص الأرباح',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'total_operations' => (clone $query)->count(),
            'total_profit' => $this->sumCommissionUsd((clone $query)->where('status', OperationStatus::Completed->value)),
            'total_profit_usd' => $this->sumCommissionUsd((clone $query)->where('status', OperationStatus::Completed->value)),
            'completed_operations' => (clone $query)->where('status', OperationStatus::Completed->value)->count(),
            'pending_operations' => (clone $query)->where('status', OperationStatus::Pending->value)->count(),
            'cancelled_operations' => (clone $query)->where('status', OperationStatus::Cancelled->value)->count(),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function suppliers(array $params, User $user): array
    {
        $customersTable = (new Customer)->getTable();
        $operationsTable = (new Operation)->getTable();
        $query = $this->operationsQuery($params, $user)->whereNotNull("{$operationsTable}.supplier_id");

        $rows = (clone $query)
            ->join($customersTable, "{$customersTable}.id", '=', "{$operationsTable}.supplier_id")
            ->selectRaw("{$operationsTable}.supplier_id")
            ->selectRaw("{$customersTable}.name as supplier")
            ->selectRaw('COUNT(*) as operation_count')
            ->selectRaw($this->statusCountSql(OperationStatus::Completed).' as completed_count')
            ->selectRaw($this->statusCountSql(OperationStatus::Pending).' as pending_count')
            ->selectRaw($this->statusCountSql(OperationStatus::Cancelled).' as cancelled_count')
            ->selectRaw("SUM({$operationsTable}.customer_amount) as transferred_amount")
            ->selectRaw($this->commissionSql().' as total_commissions')
            ->groupBy("{$operationsTable}.supplier_id", "{$customersTable}.name")
            ->orderBy("{$customersTable}.name")
            ->get()
            ->map(fn ($row): array => [
                'supplier_id' => (int) $row->supplier_id,
                'supplier' => $row->supplier,
                'operation_count' => (int) $row->operation_count,
                'completed_count' => (int) $row->completed_count,
                'pending_count' => (int) $row->pending_count,
                'cancelled_count' => (int) $row->cancelled_count,
                'transferred_amount' => round((float) $row->transferred_amount, 4),
                'total_commissions' => round((float) $row->total_commissions, 4),
            ])
            ->values()
            ->all();

        return $this->rowsReport('suppliers', 'تقرير الموردين', $params, $rows);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function profitBySupplier(array $params, User $user): array
    {
        $report = $this->suppliers($params, $user);
        $report['type'] = 'profit-by-supplier';
        $report['title'] = 'الأرباح حسب المورد';
        $report['rows'] = collect($report['rows'])
            ->map(fn (array $row): array => [
                'supplier_id' => $row['supplier_id'],
                'supplier' => $row['supplier'],
                'operations_count' => $row['completed_count'],
                'total_profit_usd' => $row['total_commissions'],
            ])
            ->values()
            ->all();
        $report['total_profit_usd'] = round((float) collect($report['rows'])->sum('total_profit_usd'), 4);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function customers(array $params, User $user): array
    {
        $customersTable = (new Customer)->getTable();
        $operationsTable = (new Operation)->getTable();

        $rows = $this->operationsQuery($params, $user)
            ->join($customersTable, "{$customersTable}.id", '=', "{$operationsTable}.customer_id")
            ->selectRaw("{$operationsTable}.customer_id")
            ->selectRaw("{$customersTable}.name as customer")
            ->selectRaw('COUNT(*) as operation_count')
            ->selectRaw("SUM({$operationsTable}.customer_net_amount) as total_received_amount")
            ->selectRaw("SUM(COALESCE({$operationsTable}.supplier_amount, 0)) as total_sent_amount")
            ->selectRaw("MAX({$operationsTable}.transaction_date) as last_operation")
            ->groupBy("{$operationsTable}.customer_id", "{$customersTable}.name")
            ->orderBy("{$customersTable}.name")
            ->get()
            ->map(fn ($row): array => [
                'customer_id' => (int) $row->customer_id,
                'customer' => $row->customer,
                'operation_count' => (int) $row->operation_count,
                'total_received_amount' => round((float) $row->total_received_amount, 4),
                'total_sent_amount' => round((float) $row->total_sent_amount, 4),
                'last_operation' => Carbon::parse($row->last_operation)->toDateString(),
            ])
            ->values()
            ->all();

        return $this->rowsReport('customers', 'تقرير العملاء', $params, $rows);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function statement(array $params, User $user): array
    {
        $report = $this->customers($params, $user);
        $report['type'] = 'statement';
        $report['title'] = 'كشف عمليات عميل';

        return $report;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function boxes(array $params, User $user): array
    {
        $boxesTable = (new Box)->getTable();
        $operationsTable = (new Operation)->getTable();
        $operations = $this->operationsQuery($params, $user);

        $rows = Box::query()
            ->leftJoinSub(
                (clone $operations)
                    ->selectRaw("{$operationsTable}.box_id")
                    ->selectRaw('COUNT(*) as operations_count')
                    ->selectRaw("SUM(CASE WHEN {$operationsTable}.box_id IS NOT NULL THEN {$operationsTable}.customer_amount ELSE 0 END) as outgoing_amount")
                    ->selectRaw("SUM(CASE WHEN {$operationsTable}.supplier_id IS NOT NULL THEN {$operationsTable}.customer_amount ELSE 0 END) as incoming_amount")
                    ->selectRaw("MAX({$operationsTable}.transaction_date) as last_operation")
                    ->whereNotNull("{$operationsTable}.box_id")
                    ->groupBy("{$operationsTable}.box_id"),
                'operation_totals',
                "{$boxesTable}.id",
                '=',
                'operation_totals.box_id'
            )
            ->when(isset($params['box_id']), fn (Builder $query): Builder => $query->where("{$boxesTable}.id", (int) $params['box_id']))
            ->when(! $user->isOwner(), fn (Builder $query): Builder => $query->where("{$boxesTable}.assigned_user_id", $user->id))
            ->selectRaw("{$boxesTable}.id as box_id")
            ->selectRaw("{$boxesTable}.name as box")
            ->selectRaw("{$boxesTable}.current_balance")
            ->selectRaw('COALESCE(operation_totals.operations_count, 0) as operations_count')
            ->selectRaw('COALESCE(operation_totals.outgoing_amount, 0) as outgoing_amount')
            ->selectRaw('COALESCE(operation_totals.incoming_amount, 0) as incoming_amount')
            ->selectRaw('operation_totals.last_operation')
            ->orderBy("{$boxesTable}.name")
            ->get()
            ->map(fn ($row): array => [
                'box_id' => (int) $row->box_id,
                'box' => $row->box,
                'current_balance' => round((float) $row->current_balance, 4),
                'operations_count' => (int) $row->operations_count,
                'outgoing_amount' => round((float) $row->outgoing_amount, 4),
                'incoming_amount' => round((float) $row->incoming_amount, 4),
                'last_operation' => $row->last_operation === null ? null : Carbon::parse($row->last_operation)->toDateString(),
            ])
            ->values()
            ->all();

        return $this->rowsReport('boxes', 'تقرير الصناديق', $params, $rows);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function pending(array $params, User $user): array
    {
        return $this->detailedReport(
            'pending',
            'تقرير العمليات المعلقة',
            $params,
            $this->operationsQuery($params, $user)
                ->with(['supplier:id,name', 'customer:id,name'])
                ->where('status', OperationStatus::Pending->value)
                ->whereNotNull('supplier_id')
                ->oldest('transaction_date')
                ->oldest('id')
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function cancelled(array $params, User $user): array
    {
        return $this->detailedReport(
            'cancelled',
            'تقرير العمليات الملغاة',
            $params,
            $this->operationsQuery($params, $user)
                ->with(['supplier:id,name', 'customer:id,name'])
                ->where('status', OperationStatus::Cancelled->value)
                ->latest('cancelled_at')
                ->latest('id')
        );
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

        $usersTable = (new User)->getTable();
        $operationsTable = (new Operation)->getTable();

        $rows = $this->operationsQuery($params, $user)
            ->join($usersTable, "{$usersTable}.id", '=', "{$operationsTable}.created_by")
            ->selectRaw("{$operationsTable}.created_by as user_id")
            ->selectRaw("{$usersTable}.name as user_name")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw("SUM({$operationsTable}.customer_amount) as transferred_amount")
            ->selectRaw($this->commissionSql().' as total_commission')
            ->groupBy("{$operationsTable}.created_by", "{$usersTable}.name")
            ->orderBy("{$usersTable}.name")
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'user_name' => $row->user_name,
                'transferred_amount' => round((float) $row->transferred_amount, 4),
                'total_commission' => round((float) $row->total_commission, 4),
                'count' => (int) $row->count,
            ])
            ->values();

        return [
            'type' => 'comparison',
            'title' => 'مقارنة المستخدمين',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'rows' => $rows,
            'totals' => [
                'transferred_amount' => round((float) $rows->sum('transferred_amount'), 4),
                'total_commission' => round((float) $rows->sum('total_commission'), 4),
                'count' => (int) $rows->sum('count'),
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function profitByUser(array $params, User $user): array
    {
        $usersTable = (new User)->getTable();
        $operationsTable = (new Operation)->getTable();

        $rows = $this->completedOperationsQuery($params, $user)
            ->join($usersTable, "{$usersTable}.id", '=', "{$operationsTable}.created_by")
            ->selectRaw("{$operationsTable}.created_by as user_id")
            ->selectRaw("{$usersTable}.name as employee")
            ->selectRaw('COUNT(*) as operations_count')
            ->selectRaw('SUM(commission_amount) as total_profit_usd')
            ->groupBy("{$operationsTable}.created_by", "{$usersTable}.name")
            ->orderByDesc('total_profit_usd')
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'employee' => $row->employee,
                'operations_count' => (int) $row->operations_count,
                'total_profit_usd' => round((float) $row->total_profit_usd, 4),
            ])
            ->values()
            ->all();

        return [
            'type' => 'profit-by-user',
            'title' => 'الأرباح حسب الموظف',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'rows' => $rows,
            'total_profit_usd' => round((float) collect($rows)->sum('total_profit_usd'), 4),
            'generated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Builder<Operation>
     */
    private function operationsQuery(array $params, User $user): Builder
    {
        $query = Operation::query();

        if ($user->isOwner()) {
            $query->when(isset($params['user_id']), fn (Builder $query): Builder => $query->where('created_by', (int) $params['user_id']));
        } else {
            $query->where('created_by', $user->id);
        }

        return $query
            ->when(isset($params['date_from']), fn (Builder $query): Builder => $query->whereDate('transaction_date', '>=', (string) $params['date_from']))
            ->when(isset($params['date_to']), fn (Builder $query): Builder => $query->whereDate('transaction_date', '<=', (string) $params['date_to']))
            ->when(isset($params['supplier_id']), fn (Builder $query): Builder => $query->where('supplier_id', (int) $params['supplier_id']))
            ->when(isset($params['customer_id']), fn (Builder $query): Builder => $query->where('customer_id', (int) $params['customer_id']))
            ->when(isset($params['box_id']), fn (Builder $query): Builder => $query->where('box_id', (int) $params['box_id']))
            ->when(isset($params['status']), fn (Builder $query): Builder => $query->where('status', (string) $params['status']));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Builder<Operation>
     */
    private function completedOperationsQuery(array $params, User $user): Builder
    {
        return $this->operationsQuery(array_merge($params, ['status' => OperationStatus::Completed->value]), $user);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function periodFilters(array $params): array
    {
        $period = $params['period'] ?? 'custom';
        $filters = $params;

        if ($period === 'daily') {
            $date = Carbon::parse($params['date'] ?? $params['date_from'] ?? now())->toDateString();
            $filters['date_from'] = $date;
            $filters['date_to'] = $date;
        }

        if ($period === 'monthly') {
            $date = Carbon::parse($params['date'] ?? $params['date_from'] ?? now());
            $filters['date_from'] = $date->copy()->startOfMonth()->toDateString();
            $filters['date_to'] = $date->copy()->endOfMonth()->toDateString();
        }

        if ($period === 'yearly') {
            $date = Carbon::parse($params['date'] ?? $params['date_from'] ?? now());
            $filters['date_from'] = $date->copy()->startOfYear()->toDateString();
            $filters['date_to'] = $date->copy()->endOfYear()->toDateString();
        }

        $filters['period'] = $period;

        return $filters;
    }

    /**
     * @param  Builder<Operation>  $query
     * @return array{pending: int, completed: int, cancelled: int}
     */
    private function statusCounts(Builder $query): array
    {
        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            OperationStatus::Pending->value => (int) ($counts[OperationStatus::Pending->value] ?? 0),
            OperationStatus::Completed->value => (int) ($counts[OperationStatus::Completed->value] ?? 0),
            OperationStatus::Cancelled->value => (int) ($counts[OperationStatus::Cancelled->value] ?? 0),
        ];
    }

    /**
     * @param  Builder<Operation>  $query
     * @return list<array{status: string, operation_count: int, transferred_amount: float}>
     */
    private function byStatus(Builder $query): array
    {
        return (clone $query)
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as operation_count')
            ->selectRaw('SUM(customer_amount) as transferred_amount')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn ($row): array => [
                'status' => $row->status instanceof OperationStatus ? $row->status->value : (string) $row->status,
                'operation_count' => (int) $row->operation_count,
                'transferred_amount' => round((float) $row->transferred_amount, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Operation>  $query
     */
    private function sumTransferredAmount(Builder $query): float
    {
        return round((float) $query->sum('customer_amount'), 4);
    }

    /**
     * @param  Builder<Operation>  $query
     */
    private function sumCommissionUsd(Builder $query): float
    {
        return round((float) $query->sum('commission_amount'), 4);
    }

    private function statusCountSql(OperationStatus $status): string
    {
        $operationsTable = (new Operation)->getTable();

        return "SUM(CASE WHEN {$operationsTable}.status = '{$status->value}' THEN 1 ELSE 0 END)";
    }

    private function commissionSql(): string
    {
        $operationsTable = (new Operation)->getTable();

        return "SUM(CASE WHEN {$operationsTable}.status = '".OperationStatus::Completed->value."' THEN {$operationsTable}.commission_amount ELSE 0 END)";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowsReport(string $type, string $title, array $params, array $rows): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'rows' => $rows,
            'generated_at' => now(),
        ];
    }

    /**
     * @param  Builder<Operation>  $query
     * @return array<string, mixed>
     */
    private function detailedReport(string $type, string $title, array $params, Builder $query): array
    {
        $paginator = $query->paginate((int) ($params['per_page'] ?? 20));

        return [
            'type' => $type,
            'title' => $title,
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'operations' => $this->operationRows($paginator),
            'meta' => [
                'total' => $paginator->total(),
                'count' => $paginator->count(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operationRows(LengthAwarePaginator $paginator): array
    {
        /** @var Collection<int, Operation> $operations */
        $operations = $paginator->getCollection();

        return $operations
            ->map(fn (Operation $operation): array => [
                'reference_number' => $operation->reference_number,
                'supplier' => $operation->supplier?->name,
                'customer' => $operation->customer?->name,
                'amount' => round((float) $operation->customer_amount, 4),
                'commission' => round((float) $operation->commission_amount, 4),
                'status' => $operation->status->value,
                'created_at' => $operation->created_at?->toDateTimeString(),
                'cancelled_at' => $operation->cancelled_at?->toDateTimeString(),
                'cancellation_reason' => $operation->cancellation_reason,
            ])
            ->values()
            ->all();
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
