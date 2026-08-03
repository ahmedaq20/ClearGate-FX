<?php

namespace App\Services;

use App\Exports\ReportArrayExport;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExcelService
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function save(string $type, array $report, string $path): void
    {
        Storage::disk('local')->makeDirectory(dirname($path));

        Excel::store(
            new ReportArrayExport($this->headings($type), $this->rows($type, $report)),
            $path,
            'local'
        );
    }

    /**
     * @return list<string>
     */
    private function headings(string $type): array
    {
        return match ($type) {
            'operations' => ['Metric', 'Value'],
            'commissions' => ['Metric', 'Value'],
            'comparison' => ['User ID', 'User Name', 'Transferred Amount USD', 'Total Commission USD', 'Count'],
            'profit-summary' => ['Metric', 'Value'],
            'daily-profit' => ['Date', 'Operations Count', 'Total Profit USD'],
            'monthly-profit' => ['Month', 'Operations Count', 'Total Profit USD'],
            'profit-by-supplier' => ['Supplier ID', 'Supplier', 'Operations Count', 'Total Profit USD'],
            'profit-by-user' => ['User ID', 'Employee', 'Operations Count', 'Total Profit USD'],
            'suppliers' => ['Supplier ID', 'Supplier', 'Operations', 'Completed', 'Pending', 'Cancelled', 'Transferred Amount USD', 'Total Commissions USD'],
            'customers' => ['Customer ID', 'Customer', 'Operations', 'Total Received Amount USD', 'Total Sent Amount USD', 'Last Operation'],
            'boxes' => ['Box ID', 'Box', 'Current Balance', 'Operations', 'Outgoing Amount', 'Incoming Amount', 'Last Operation'],
            'pending' => ['Reference', 'Supplier', 'Customer', 'Amount USD', 'Commission USD', 'Created At'],
            'cancelled' => ['Reference', 'Supplier', 'Customer', 'Amount USD', 'Commission USD', 'Cancellation Reason', 'Cancelled At'],
            'obligations', 'operation-obligations' => ['Operation', 'Counterparty', 'Role', 'Type', 'Reason', 'Amount', 'Currency', 'Settled', 'Balance', 'Status'],
            'operations-workflow' => ['Reference', 'Customer', 'Customer Amount', 'Customer Currency', 'Supplier', 'Supplier Amount', 'Supplier Currency', 'Supplier Direction', 'Commission', 'Commission Payer', 'Customer Commission', 'Supplier Commission', 'Status', 'Customer Settlement', 'Supplier Fulfillment', 'Supplier Settlement', 'Outstanding'],
            'workflow-reconciliation', 'reconciliation' => ['Issue Type', 'Operation ID', 'Reference', 'Currency', 'Actual', 'Expected', 'Details'],
            default => ['Reference', 'Supplier', 'Customer', 'Amount USD', 'Commission USD', 'Status', 'Created At'],
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<int, mixed>>
     */
    private function rows(string $type, array $report): array
    {
        if ($type === 'operations') {
            $rows = [
                ['Total Operations', $report['total_operations']],
                ['Completed', $report['completed']],
                ['Pending', $report['pending']],
                ['Cancelled', $report['cancelled']],
                ['Total Transferred Amount USD', $report['total_transferred_amount']],
            ];

            foreach ($report['by_status'] ?? [] as $row) {
                $rows[] = ["Status {$row['status']} Count", $row['operation_count']];
                $rows[] = ["Status {$row['status']} Amount USD", $row['transferred_amount']];
            }

            return $rows;
        }

        if ($type === 'commissions') {
            return [
                ['Total Commission USD', $report['total_commission']],
                ['Average Commission USD', $report['average_commission']],
                ['Operation Count', $report['operation_count']],
                ['Period', $report['period']],
            ];
        }

        if ($type === 'comparison') {
            return $report['rows']->map(fn (array $row): array => [
                $row['user_id'],
                $row['user_name'],
                $row['transferred_amount'],
                $row['total_commission'],
                $row['count'],
            ])->all();
        }

        if ($type === 'profit-summary') {
            return [
                ['Total Operations', $report['total_operations']],
                ['Completed Operations', $report['completed_operations']],
                ['Pending Operations', $report['pending_operations']],
                ['Cancelled Operations', $report['cancelled_operations']],
                ['Total Profit USD', $report['total_profit_usd'] ?? $report['total_profit']],
            ];
        }

        if ($type === 'daily-profit') {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['date'],
                $row['operations_count'],
                $row['total_profit_usd'],
            ])->all();
        }

        if ($type === 'monthly-profit') {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['month'],
                $row['operations_count'],
                $row['total_profit_usd'],
            ])->all();
        }

        if ($type === 'profit-by-supplier') {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['supplier_id'],
                $row['supplier'],
                $row['operations_count'],
                $row['total_profit_usd'],
            ])->all();
        }

        if ($type === 'profit-by-user') {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['user_id'],
                $row['employee'],
                $row['operations_count'],
                $row['total_profit_usd'],
            ])->all();
        }

        if ($type === 'suppliers') {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['supplier_id'],
                $row['supplier'],
                $row['operation_count'],
                $row['completed_count'],
                $row['pending_count'],
                $row['cancelled_count'],
                $row['transferred_amount'],
                $row['total_commissions'],
            ])->all();
        }

        if ($type === 'customers') {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['customer_id'],
                $row['customer'],
                $row['operation_count'],
                $row['total_received_amount'],
                $row['total_sent_amount'],
                $row['last_operation'],
            ])->all();
        }

        if ($type === 'boxes') {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['box_id'],
                $row['box'],
                $row['current_balance'],
                $row['operations_count'],
                $row['outgoing_amount'],
                $row['incoming_amount'],
                $row['last_operation'],
            ])->all();
        }

        if ($type === 'pending') {
            return collect($report['operations'])->map(fn (array $row): array => [
                $row['reference_number'],
                $row['supplier'],
                $row['customer'],
                $row['amount'],
                $row['commission'],
                $row['created_at'],
            ])->all();
        }

        if (in_array($type, ['obligations', 'operation-obligations'], true)) {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['reference_number'],
                $row['counterparty'],
                $row['counterparty_role'],
                $row['type'],
                $row['reason'],
                $row['amount'],
                $row['currency'],
                $row['settled_amount'],
                $row['balance_amount'],
                $row['status'],
            ])->all();
        }

        if ($type === 'operations-workflow') {
            return collect($report['rows'])->map(fn (array $row): array => [
                $row['reference_number'],
                $row['customer'],
                $row['customer_amount'],
                $row['customer_currency'],
                $row['supplier'],
                $row['supplier_amount'],
                $row['supplier_currency'],
                $row['supplier_direction'],
                $row['commission_amount'],
                $row['commission_payer'],
                $row['customer_commission_amount'],
                $row['supplier_commission_amount'],
                $row['status'],
                $row['customer_settlement_status'],
                $row['supplier_fulfillment_status'],
                $row['supplier_settlement_status'],
                $this->compactJson($row['outstanding']),
            ])->all();
        }

        if (in_array($type, ['workflow-reconciliation', 'reconciliation'], true)) {
            return collect($report['issues'])->map(fn (array $row): array => [
                $row['type'],
                $row['operation_id'] ?? null,
                $row['reference_number'] ?? null,
                $row['currency'] ?? null,
                $row['actual_status'] ?? $row['settled_amount'] ?? $row['settlement_amount'] ?? null,
                $row['expected_status'] ?? $row['settlements_amount'] ?? $row['expected_box_operation_type'] ?? null,
                $this->compactJson($row),
            ])->all();
        }

        return collect($report['operations'])->map(fn (array $row): array => [
            $row['reference_number'],
            $row['supplier'],
            $row['customer'],
            $row['amount'],
            $row['commission'],
            $row['cancellation_reason'],
            $row['cancelled_at'],
        ])->all();
    }

    private function compactJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
