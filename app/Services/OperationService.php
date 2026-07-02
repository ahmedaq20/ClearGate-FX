<?php

namespace App\Services;

use App\Enums\BoxBalanceOperationType;
use App\Enums\OperationStatus;
use App\Models\AuditLog;
use App\Models\Box;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, User $user): Operation
    {
        return DB::transaction(function () use ($data, $user): Operation {
            $operation = Operation::query()->create($this->operationPayload($data, $user));

            $this->applyBoxFunding($operation, $user, BoxBalanceOperationType::Subtract);
            $this->applySupplierFunding($operation);

            AuditLog::record(
                action: 'operation.created',
                model: $operation,
                userId: $user->id,
                newValues: $operation->attributesToArray()
            );

            return $operation;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Operation $operation, array $data, User $user): Operation
    {
        return DB::transaction(function () use ($operation, $data, $user): Operation {
            $lockedOperation = Operation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $lockedOperation->attributesToArray();
            $payload = $this->operationPayload($this->mergeOperationData($lockedOperation, $data), $user, $lockedOperation);

            $this->applyBoxFunding($lockedOperation, $user, BoxBalanceOperationType::Add);
            $this->reverseSupplierFunding($lockedOperation);

            $lockedOperation->update($payload);
            $lockedOperation->refresh();

            $this->applyBoxFunding($lockedOperation, $user, BoxBalanceOperationType::Subtract);
            $this->applySupplierFunding($lockedOperation);

            AuditLog::record(
                action: 'operation.updated',
                model: $lockedOperation,
                userId: $user->id,
                oldValues: $oldValues,
                newValues: $lockedOperation->attributesToArray()
            );

            return $lockedOperation;
        }, attempts: 3);
    }

    public function delete(Operation $operation, User $user): void
    {
        DB::transaction(function () use ($operation, $user): void {
            $lockedOperation = Operation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $lockedOperation->attributesToArray();

            $this->applyBoxFunding($lockedOperation, $user, BoxBalanceOperationType::Add);
            $this->reverseSupplierFunding($lockedOperation);

            AuditLog::record(
                action: 'operation.deleted',
                model: $lockedOperation,
                userId: $user->id,
                oldValues: $oldValues
            );

            $lockedOperation->delete();
        }, attempts: 3);
    }

    public function complete(Operation $operation, User $user): Operation
    {
        return DB::transaction(function () use ($operation, $user): Operation {
            $lockedOperation = Operation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $lockedOperation->attributesToArray();

            if ($lockedOperation->status !== OperationStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => $lockedOperation->status === OperationStatus::Completed
                        ? 'العملية مكتملة مسبقاً'
                        : 'العملية ملغاة',
                ]);
            }

            $lockedOperation->update([
                'status' => OperationStatus::Completed->value,
                'completed_at' => now(),
            ]);
            $lockedOperation->refresh();

            $this->applySupplierFunding($lockedOperation);

            AuditLog::record(
                action: 'operation.completed',
                model: $lockedOperation,
                userId: $user->id,
                oldValues: $oldValues,
                newValues: $lockedOperation->attributesToArray()
            );

            return $lockedOperation;
        }, attempts: 3);
    }

    public function cancel(Operation $operation, User $user, string $cancellationReason): Operation
    {
        return DB::transaction(function () use ($operation, $user, $cancellationReason): Operation {
            $lockedOperation = Operation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $lockedOperation->attributesToArray();

            if ($lockedOperation->status === OperationStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => 'العملية مكتملة مسبقاً',
                ]);
            }

            if ($lockedOperation->status === OperationStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'العملية ملغاة',
                ]);
            }

            $lockedOperation->update([
                'status' => OperationStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancellation_reason' => $cancellationReason,
            ]);
            $lockedOperation->refresh();

            AuditLog::record(
                action: 'operation.cancelled',
                model: $lockedOperation,
                userId: $user->id,
                oldValues: $oldValues,
                newValues: $lockedOperation->attributesToArray()
            );

            return $lockedOperation;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function operationPayload(array $data, User $user, ?Operation $operation = null): array
    {
        $isSupplierFunded = isset($data['supplier_id']) && $data['supplier_id'] !== null;
        $customerAmount = round((float) $data['customer_amount'], 4);
        $commissionAmount = $this->calculateCommissionAmount(
            $customerAmount,
            (string) $data['commission_type'],
            (float) $data['commission_rate']
        );

        $payload = [
            'reference_number' => $operation?->reference_number ?? $this->nextReferenceNumber((string) $data['transaction_date']),
            'transaction_date' => $data['transaction_date'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'box_id' => $data['box_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'supplier_currency' => $isSupplierFunded ? $data['supplier_currency'] : null,
            'supplier_amount' => $isSupplierFunded ? round((float) $data['supplier_amount'], 4) : null,
            'supplier_exchange_rate' => $isSupplierFunded ? round((float) $data['supplier_exchange_rate'], 8) : null,
            'customer_currency' => $data['customer_currency'],
            'customer_amount' => $customerAmount,
            'customer_exchange_rate' => round((float) $data['customer_exchange_rate'], 8),
            'commission_type' => $data['commission_type'],
            'commission_rate' => round((float) $data['commission_rate'], 4),
            'commission_amount' => $commissionAmount,
            'customer_net_amount' => round($customerAmount - $commissionAmount, 4),
            'notes' => $data['notes'] ?? null,
            'created_by' => $operation?->created_by ?? $user->id,
        ];

        if ($operation === null) {
            $status = isset($data['box_id']) && $data['box_id'] !== null
                ? OperationStatus::Completed
                : OperationStatus::from((string) $data['status']);

            $payload['status'] = $status->value;
            $payload['completed_at'] = $status === OperationStatus::Completed ? now() : null;
        }

        return $payload;
    }

    private function calculateCommissionAmount(float $amount, string $commissionType, float $commissionRate): float
    {
        return match ($commissionType) {
            'percentage' => round($amount * ($commissionRate / 100), 4),
            'fixed' => round($commissionRate, 4),
            default => throw ValidationException::withMessages([
                'commission_type' => 'نوع العمولة يجب أن يكون نسبة أو قيمة ثابتة.',
            ]),
        };
    }

    private function applySupplierFunding(Operation $operation): void
    {
        $this->applySupplierBalanceEffect($operation, -1);
    }

    private function reverseSupplierFunding(Operation $operation): void
    {
        $this->applySupplierBalanceEffect($operation, 1);
    }

    private function applySupplierBalanceEffect(Operation $operation, int $direction): void
    {
        if ($operation->supplier_id === null || $operation->status !== OperationStatus::Completed) {
            return;
        }

        $supplier = $operation->supplier()->lockForUpdate()->firstOrFail();
        $customer = $operation->customer()->lockForUpdate()->firstOrFail();
        $supplierAmountUsd = $this->usdValue((float) $operation->supplier_amount, (float) $operation->supplier_exchange_rate);
        $customerNetAmountUsd = $this->usdValue((float) $operation->customer_net_amount, (float) $operation->customer_exchange_rate);

        $supplier->update([
            'balance_usd' => round((float) $supplier->balance_usd + ($supplierAmountUsd * $direction), 4),
        ]);

        $customer->update([
            'balance_usd' => round((float) $customer->balance_usd - ($customerNetAmountUsd * $direction), 4),
        ]);
    }

    private function usdValue(float $amount, float $exchangeRate): float
    {
        if ($exchangeRate <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'سعر الصرف يجب أن يكون أكبر من صفر.',
            ]);
        }

        return round($amount / $exchangeRate, 4);
    }

    private function applyBoxFunding(Operation $operation, User $user, BoxBalanceOperationType $operationType): void
    {
        if ($operation->box_id === null) {
            return;
        }

        $box = Box::query()
            ->whereKey($operation->box_id)
            ->lockForUpdate()
            ->firstOrFail();
        $balanceBefore = (float) $box->current_balance;
        $amount = (float) $operation->customer_amount;
        $balanceAfter = $operationType === BoxBalanceOperationType::Subtract
            ? $balanceBefore - $amount
            : $balanceBefore + $amount;

        if ($balanceAfter < 0) {
            throw ValidationException::withMessages([
                'box_id' => 'رصيد الصندوق غير كافٍ.',
            ]);
        }

        $box->update(['current_balance' => round($balanceAfter, 4)]);
        $box->balanceLogs()->create([
            'operation_id' => $operation->id,
            'operation_type' => $operationType->value,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => round($balanceAfter, 4),
            'notes' => $operationType === BoxBalanceOperationType::Subtract
                ? "تمويل العملية {$operation->reference_number}"
                : "عكس تمويل العملية {$operation->reference_number}",
            'created_by' => $user->id,
        ]);
    }

    private function nextReferenceNumber(string $transactionDate): string
    {
        $year = date('Y', strtotime($transactionDate));
        $lastReference = Operation::query()
            ->where('reference_number', 'like', "TRX-{$year}-%")
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('reference_number');
        $nextNumber = $lastReference === null
            ? 1
            : ((int) str($lastReference)->afterLast('-')->toString()) + 1;

        return sprintf('TRX-%s-%05d', $year, $nextNumber);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeOperationData(Operation $operation, array $data): array
    {
        return array_merge(Arr::only($operation->attributesToArray(), [
            'transaction_date',
            'supplier_id',
            'box_id',
            'customer_id',
            'supplier_currency',
            'supplier_amount',
            'supplier_exchange_rate',
            'customer_currency',
            'customer_amount',
            'customer_exchange_rate',
            'commission_type',
            'commission_rate',
            'notes',
        ]), $data);
    }
}
