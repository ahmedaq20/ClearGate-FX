<?php

namespace App\Services;

use App\Enums\BoxBalanceOperationType;
use App\Enums\OperationCustomerSettlementStatus;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationSettlementDirection;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierFulfillmentStatus;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
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
            'obligations', 'operation-obligations' => $this->obligations($params, $user),
            'operations-workflow' => $this->workflow($params, $user),
            'workflow-reconciliation', 'reconciliation' => $this->workflowReconciliation($params, $user),
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
    public function obligations(array $params, User $user): array
    {
        $query = $this->obligationsQuery($params, $user);
        $paginator = (clone $query)
            ->with(['operation:id,reference_number,transaction_date,status,created_by', 'counterparty:id,name,type'])
            ->latest('id')
            ->paginate((int) ($params['per_page'] ?? 20));

        return [
            'type' => 'operation-obligations',
            'title' => 'تقرير الذمم المالية',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'currency_totals' => $this->obligationCurrencyTotals(clone $query),
            'status_totals' => $this->obligationStatusTotals(clone $query),
            'rows' => $paginator->getCollection()
                ->map(fn (OperationObligation $obligation): array => $this->obligationReportRow($obligation))
                ->values()
                ->all(),
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
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function workflow(array $params, User $user): array
    {
        $paginator = $this->operationsQuery($params, $user)
            ->with(['supplier:id,name,type', 'customer:id,name,type', 'obligations'])
            ->latest('transaction_date')
            ->latest('id')
            ->paginate((int) ($params['per_page'] ?? 20));

        return [
            'type' => 'operations-workflow',
            'title' => 'تقرير سير العمليات المالي',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'rows' => $paginator->getCollection()
                ->map(fn (Operation $operation): array => $this->workflowOperationRow($operation))
                ->values()
                ->all(),
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
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function workflowReconciliation(array $params, User $user): array
    {
        $operationsQuery = $this->operationsQuery($params, $user);
        $operationIds = (clone $operationsQuery)->pluck('id');
        $obligationIssues = $this->obligationReconciliationIssues($operationIds);
        $settlementSumIssues = $this->obligationSettlementSumIssues($operationIds);
        $settlementObligationIssues = $this->settlementObligationIssues($operationIds);
        $settlementIssues = $this->settlementReconciliationIssues($operationIds);
        $statusIssues = $this->operationWorkflowStatusIssues(clone $operationsQuery);
        $issues = array_merge($obligationIssues, $settlementSumIssues, $settlementObligationIssues, $settlementIssues, $statusIssues);

        return [
            'type' => 'workflow-reconciliation',
            'title' => 'تقرير مطابقة سير العمليات المالي',
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'summary' => [
                'operations_checked' => $operationIds->count(),
                'obligation_issues' => count($obligationIssues),
                'settlement_sum_issues' => count($settlementSumIssues),
                'settlement_obligation_issues' => count($settlementObligationIssues),
                'settlement_issues' => count($settlementIssues),
                'status_issues' => count($statusIssues),
                'total_issues' => count($issues),
            ],
            'issues' => $issues,
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
     * @return Builder<OperationObligation>
     */
    private function obligationsQuery(array $params, User $user): Builder
    {
        return OperationObligation::query()
            ->whereHas('operation', function (Builder $query) use ($params, $user): void {
                if ($user->isOwner()) {
                    $query->when(isset($params['user_id']), fn (Builder $query): Builder => $query->where('created_by', (int) $params['user_id']));
                } else {
                    $query->where('created_by', $user->id);
                }

                $query
                    ->when(isset($params['date_from']), fn (Builder $query): Builder => $query->whereDate('transaction_date', '>=', (string) $params['date_from']))
                    ->when(isset($params['date_to']), fn (Builder $query): Builder => $query->whereDate('transaction_date', '<=', (string) $params['date_to']))
                    ->when(isset($params['supplier_id']), fn (Builder $query): Builder => $query->where('supplier_id', (int) $params['supplier_id']))
                    ->when(isset($params['customer_id']), fn (Builder $query): Builder => $query->where('customer_id', (int) $params['customer_id']))
                    ->when(isset($params['status']), fn (Builder $query): Builder => $query->where('status', (string) $params['status']));
            })
            ->when(isset($params['obligation_type']), fn (Builder $query): Builder => $query->where('type', (string) $params['obligation_type']))
            ->when(isset($params['counterparty_role']), fn (Builder $query): Builder => $query->where('counterparty_role', (string) $params['counterparty_role']))
            ->when(isset($params['obligation_status']), fn (Builder $query): Builder => $query->where('status', (string) $params['obligation_status']))
            ->when(isset($params['currency']), fn (Builder $query): Builder => $query->where('currency', mb_strtoupper((string) $params['currency'])));
    }

    /**
     * @param  Builder<OperationObligation>  $query
     * @return list<array<string, mixed>>
     */
    private function obligationCurrencyTotals(Builder $query): array
    {
        return $query
            ->selectRaw('type, counterparty_role, currency')
            ->selectRaw('COUNT(*) as obligation_count')
            ->selectRaw('SUM(amount) as total_amount')
            ->selectRaw('SUM(settled_amount) as settled_amount')
            ->selectRaw('SUM(balance_amount) as balance_amount')
            ->groupBy('type', 'counterparty_role', 'currency')
            ->orderBy('type')
            ->orderBy('counterparty_role')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'type' => $this->enumValue($row->type),
                'counterparty_role' => $this->enumValue($row->counterparty_role),
                'currency' => (string) $row->currency,
                'obligation_count' => (int) $row->obligation_count,
                'total_amount' => round((float) $row->total_amount, 4),
                'settled_amount' => round((float) $row->settled_amount, 4),
                'balance_amount' => round((float) $row->balance_amount, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<OperationObligation>  $query
     * @return list<array<string, mixed>>
     */
    private function obligationStatusTotals(Builder $query): array
    {
        return $query
            ->selectRaw('status, currency')
            ->selectRaw('COUNT(*) as obligation_count')
            ->selectRaw('SUM(balance_amount) as balance_amount')
            ->groupBy('status', 'currency')
            ->orderBy('status')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'status' => $this->enumValue($row->status),
                'currency' => (string) $row->currency,
                'obligation_count' => (int) $row->obligation_count,
                'balance_amount' => round((float) $row->balance_amount, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function obligationReportRow(OperationObligation $obligation): array
    {
        return [
            'id' => $obligation->id,
            'operation_id' => $obligation->operation_id,
            'reference_number' => $obligation->operation?->reference_number,
            'transaction_date' => $obligation->operation?->transaction_date?->toDateString(),
            'counterparty_id' => $obligation->counterparty_id,
            'counterparty' => $obligation->counterparty?->name,
            'counterparty_role' => $obligation->counterparty_role->value,
            'type' => $obligation->type->value,
            'reason' => $obligation->reason->value,
            'amount' => round((float) $obligation->amount, 4),
            'currency' => $obligation->currency,
            'exchange_rate' => $obligation->exchange_rate === null ? null : round((float) $obligation->exchange_rate, 8),
            'settled_amount' => round((float) $obligation->settled_amount, 4),
            'balance_amount' => round((float) $obligation->balance_amount, 4),
            'status' => $obligation->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowOperationRow(Operation $operation): array
    {
        return [
            'id' => $operation->id,
            'reference_number' => $operation->reference_number,
            'transaction_date' => $operation->transaction_date?->toDateString(),
            'status' => $operation->status->value,
            'customer_id' => $operation->customer_id,
            'customer' => $operation->customer?->name,
            'customer_amount' => round((float) $operation->customer_amount, 4),
            'customer_currency' => $operation->customer_currency,
            'supplier_id' => $operation->supplier_id,
            'supplier' => $operation->supplier?->name,
            'supplier_amount' => $operation->supplier_amount === null ? null : round((float) $operation->supplier_amount, 4),
            'supplier_currency' => $operation->supplier_currency,
            'supplier_direction' => $operation->supplier_direction?->value,
            'commission_amount' => round((float) $operation->commission_amount, 4),
            'commission_currency' => $operation->commission_currency,
            'customer_settlement_status' => $operation->customer_settlement_status?->value,
            'supplier_fulfillment_status' => $operation->supplier_fulfillment_status?->value,
            'supplier_settlement_status' => $operation->supplier_settlement_status?->value,
            'outstanding' => $this->operationOutstandingByCurrency($operation->obligations),
        ];
    }

    /**
     * @param  Collection<int, OperationObligation>  $obligations
     * @return list<array<string, mixed>>
     */
    private function operationOutstandingByCurrency(Collection $obligations): array
    {
        return $obligations
            ->filter(fn (OperationObligation $obligation): bool => round((float) $obligation->balance_amount, 4) > 0)
            ->groupBy(fn (OperationObligation $obligation): string => "{$obligation->type->value}|{$obligation->counterparty_role->value}|{$obligation->currency}")
            ->map(function (Collection $group): array {
                /** @var OperationObligation $first */
                $first = $group->first();

                return [
                    'type' => $first->type->value,
                    'counterparty_role' => $first->counterparty_role->value,
                    'currency' => $first->currency,
                    'balance_amount' => round((float) $group->sum('balance_amount'), 4),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $operationIds
     * @return list<array<string, mixed>>
     */
    private function settlementObligationIssues(Collection $operationIds): array
    {
        return OperationSettlement::query()
            ->whereIn('operation_id', $operationIds)
            ->whereNotNull('operation_obligation_id')
            ->with('obligation')
            ->get()
            ->filter(function (OperationSettlement $settlement): bool {
                $obligation = $settlement->obligation;

                if ($obligation === null) {
                    return true;
                }

                return $settlement->currency !== $obligation->currency
                    || $settlement->direction !== $this->expectedSettlementDirectionForObligation($obligation)
                    || (int) $settlement->counterparty_id !== (int) $obligation->counterparty_id
                    || $settlement->counterparty_role !== $obligation->counterparty_role;
            })
            ->map(function (OperationSettlement $settlement): array {
                $obligation = $settlement->obligation;

                return [
                    'type' => 'settlement_obligation_mismatch',
                    'operation_id' => $settlement->operation_id,
                    'operation_settlement_id' => $settlement->id,
                    'operation_obligation_id' => $settlement->operation_obligation_id,
                    'settlement_currency' => $settlement->currency,
                    'obligation_currency' => $obligation?->currency,
                    'settlement_direction' => $settlement->direction->value,
                    'expected_direction' => $obligation === null ? null : $this->expectedSettlementDirectionForObligation($obligation)->value,
                    'settlement_counterparty_id' => $settlement->counterparty_id,
                    'obligation_counterparty_id' => $obligation?->counterparty_id,
                    'settlement_counterparty_role' => $settlement->counterparty_role->value,
                    'obligation_counterparty_role' => $obligation?->counterparty_role?->value,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $operationIds
     * @return list<array<string, mixed>>
     */
    private function obligationSettlementSumIssues(Collection $operationIds): array
    {
        return OperationObligation::query()
            ->whereIn('operation_id', $operationIds)
            ->withSum('settlements as settlements_amount', 'amount')
            ->get()
            ->filter(function (OperationObligation $obligation): bool {
                if ($obligation->status === OperationObligationStatus::Cancelled) {
                    return false;
                }

                return round((float) $obligation->settled_amount, 4) !== round((float) ($obligation->settlements_amount ?? 0), 4);
            })
            ->map(fn (OperationObligation $obligation): array => [
                'type' => 'obligation_settlement_sum_mismatch',
                'operation_id' => $obligation->operation_id,
                'operation_obligation_id' => $obligation->id,
                'currency' => $obligation->currency,
                'settled_amount' => round((float) $obligation->settled_amount, 4),
                'settlements_amount' => round((float) ($obligation->settlements_amount ?? 0), 4),
                'status' => $obligation->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $operationIds
     * @return list<array<string, mixed>>
     */
    private function obligationReconciliationIssues(Collection $operationIds): array
    {
        return OperationObligation::query()
            ->whereIn('operation_id', $operationIds)
            ->get()
            ->filter(function (OperationObligation $obligation): bool {
                $amount = round((float) $obligation->amount, 4);
                $settledAmount = round((float) $obligation->settled_amount, 4);
                $balanceAmount = round((float) $obligation->balance_amount, 4);

                if (round($settledAmount + $balanceAmount, 4) !== $amount) {
                    return true;
                }

                return match ($obligation->status) {
                    OperationObligationStatus::Open => $settledAmount !== 0.0 || $balanceAmount !== $amount,
                    OperationObligationStatus::PartiallySettled => ! ($settledAmount > 0 && $balanceAmount > 0),
                    OperationObligationStatus::Settled => $balanceAmount !== 0.0 || $settledAmount !== $amount,
                    OperationObligationStatus::Cancelled => false,
                };
            })
            ->map(fn (OperationObligation $obligation): array => [
                'type' => 'obligation_balance_mismatch',
                'operation_id' => $obligation->operation_id,
                'operation_obligation_id' => $obligation->id,
                'currency' => $obligation->currency,
                'amount' => round((float) $obligation->amount, 4),
                'settled_amount' => round((float) $obligation->settled_amount, 4),
                'balance_amount' => round((float) $obligation->balance_amount, 4),
                'status' => $obligation->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $operationIds
     * @return list<array<string, mixed>>
     */
    private function settlementReconciliationIssues(Collection $operationIds): array
    {
        return OperationSettlement::query()
            ->whereIn('operation_id', $operationIds)
            ->whereNotNull('box_id')
            ->with('boxBalanceLogs')
            ->get()
            ->filter(function (OperationSettlement $settlement): bool {
                $logs = $settlement->boxBalanceLogs;
                $log = $logs->first();
                $expectedOperationType = $this->boxOperationTypeForSettlement($settlement);

                return $logs->count() !== 1
                    || round((float) $logs->sum('amount'), 4) !== round((float) $settlement->amount, 4)
                    || ($log !== null && $log->operation_type !== $expectedOperationType)
                    || ($log !== null && (int) $log->box_id !== (int) $settlement->box_id)
                    || ($log !== null && (int) $log->operation_id !== (int) $settlement->operation_id);
            })
            ->map(fn (OperationSettlement $settlement): array => [
                'type' => 'settlement_box_log_mismatch',
                'operation_id' => $settlement->operation_id,
                'operation_settlement_id' => $settlement->id,
                'box_id' => $settlement->box_id,
                'currency' => $settlement->currency,
                'settlement_amount' => round((float) $settlement->amount, 4),
                'box_log_count' => $settlement->boxBalanceLogs->count(),
                'box_log_amount' => round((float) $settlement->boxBalanceLogs->sum('amount'), 4),
                'direction' => $settlement->direction->value,
                'expected_box_operation_type' => $this->boxOperationTypeForSettlement($settlement)->value,
                'box_log_operation_id' => $settlement->boxBalanceLogs->first()?->operation_id,
                'box_log_box_id' => $settlement->boxBalanceLogs->first()?->box_id,
                'box_operation_type' => $settlement->boxBalanceLogs->first()?->operation_type?->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Operation>  $query
     * @return list<array<string, mixed>>
     */
    private function operationWorkflowStatusIssues(Builder $query): array
    {
        return $query
            ->get()
            ->filter(fn (Operation $operation): bool => $this->expectedOperationStatus($operation) !== $operation->status)
            ->map(fn (Operation $operation): array => [
                'type' => 'operation_status_mismatch',
                'operation_id' => $operation->id,
                'reference_number' => $operation->reference_number,
                'actual_status' => $operation->status->value,
                'expected_status' => $this->expectedOperationStatus($operation)->value,
                'customer_settlement_status' => $operation->customer_settlement_status?->value,
                'supplier_fulfillment_status' => $operation->supplier_fulfillment_status?->value,
            ])
            ->values()
            ->all();
    }

    private function boxOperationTypeForSettlement(OperationSettlement $settlement): BoxBalanceOperationType
    {
        return $settlement->direction === OperationSettlementDirection::CashIn
            ? BoxBalanceOperationType::Add
            : BoxBalanceOperationType::Subtract;
    }

    private function expectedSettlementDirectionForObligation(OperationObligation $obligation): OperationSettlementDirection
    {
        return $obligation->type === OperationObligationType::Receivable
            ? OperationSettlementDirection::CashIn
            : OperationSettlementDirection::CashOut;
    }

    private function expectedOperationStatus(Operation $operation): OperationStatus
    {
        if ($operation->status === OperationStatus::Cancelled) {
            return OperationStatus::Cancelled;
        }

        $customerSettled = $operation->customer_settlement_status === OperationCustomerSettlementStatus::Completed;
        $supplierExecuted = $operation->supplier_id === null
            || $operation->supplier_fulfillment_status === OperationSupplierFulfillmentStatus::Completed;

        return $customerSettled && $supplierExecuted
            ? OperationStatus::Completed
            : OperationStatus::Pending;
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

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
