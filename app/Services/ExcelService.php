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
            'comparison' => ['User ID', 'User Name', 'Receive USD', 'Send USD', 'Net USD', 'Count'],
            'profit-summary' => ['Metric', 'Value'],
            'daily-profit' => ['Date', 'Operations Count', 'Total Profit USD'],
            'monthly-profit' => ['Month', 'Operations Count', 'Total Profit USD'],
            'profit-by-supplier' => ['Supplier ID', 'Supplier', 'Operations Count', 'Total Profit USD'],
            'profit-by-user' => ['User ID', 'Employee', 'Operations Count', 'Total Profit USD'],
            default => ['ID', 'Date', 'Type', 'Customer', 'User', 'Currency', 'Amount', 'Rate', 'USD Value', 'Commission USD', 'Net USD', 'Reference', 'Deleted At'],
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<int, mixed>>
     */
    private function rows(string $type, array $report): array
    {
        if ($type === 'comparison') {
            return $report['rows']->map(fn (array $row): array => [
                $row['user_id'],
                $row['user_name'],
                $row['receive'],
                $row['send'],
                $row['net'],
                $row['count'],
            ])->all();
        }

        if ($type === 'profit-summary') {
            return [
                ['Total Operations', $report['total_operations']],
                ['Completed Operations', $report['completed_operations']],
                ['Pending Operations', $report['pending_operations']],
                ['Cancelled Operations', $report['cancelled_operations']],
                ['Total Profit USD', $report['total_profit_usd']],
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

        return $report['transactions']
            ->map(fn ($transaction): array => [
                $transaction->id,
                $transaction->transaction_date?->toDateString(),
                $transaction->type,
                $transaction->customer?->name,
                $transaction->user?->name,
                $transaction->currency_code,
                (float) $transaction->amount,
                (float) $transaction->exchange_rate,
                (float) $transaction->usd_value,
                (float) $transaction->commission_usd,
                (float) $transaction->net_usd_value,
                $transaction->reference_number,
                $transaction->deleted_at?->toDateTimeString(),
            ])
            ->all();
    }
}
