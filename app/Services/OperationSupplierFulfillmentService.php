<?php

namespace App\Services;

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationReason;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierDirection;
use App\Enums\OperationSupplierFulfillmentStatus;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationSupplierFulfillmentService
{
    public function __construct(
        private OperationObligationService $obligationService,
        private OperationStateTransitionService $stateTransitionService,
    ) {}

    /**
     * @param  array{supplier_fulfillment_status: string}  $data
     */
    public function update(Operation $operation, User $user, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $user, $data): Operation {
            $lockedOperation = Operation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $lockedOperation->attributesToArray();
            $status = OperationSupplierFulfillmentStatus::from((string) $data['supplier_fulfillment_status']);

            if ($lockedOperation->status === OperationStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن تحديث تنفيذ المورد لعملية ملغاة.',
                ]);
            }

            if ($lockedOperation->supplier_id === null) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'لا يمكن تسجيل تنفيذ مورد لعملية لا تحتوي على مورد.',
                ]);
            }

            if (
                $lockedOperation->supplier_fulfillment_status === OperationSupplierFulfillmentStatus::Completed
                && $status === OperationSupplierFulfillmentStatus::Pending
            ) {
                throw ValidationException::withMessages([
                    'supplier_fulfillment_status' => 'لا يمكن إعادة تنفيذ المورد إلى معلق بعد اكتماله.',
                ]);
            }

            if ($status === OperationSupplierFulfillmentStatus::Completed) {
                $this->markCompleted($lockedOperation, $user);
            } else {
                $this->markPending($lockedOperation);
            }

            $this->stateTransitionService->sync($lockedOperation);
            $lockedOperation->refresh();

            AuditLog::record(
                action: 'operation.supplier_fulfillment.updated',
                model: $lockedOperation,
                userId: $user->id,
                oldValues: $oldValues,
                newValues: $lockedOperation->attributesToArray()
            );

            return $lockedOperation;
        }, attempts: 3);
    }

    private function markPending(Operation $operation): void
    {
        if ($operation->supplier_fulfillment_status === OperationSupplierFulfillmentStatus::Completed) {
            throw ValidationException::withMessages([
                'supplier_fulfillment_status' => 'تنفيذ المورد مكتمل مسبقاً.',
            ]);
        }

        $operation->update([
            'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Pending->value,
            'supplier_fulfilled_at' => null,
        ]);
    }

    private function markCompleted(Operation $operation, User $user): void
    {
        $supplierAmount = $this->supplierAmount($operation);
        $supplierCurrency = $this->supplierCurrency($operation);
        $supplierExchangeRate = $this->supplierExchangeRate($operation);
        $supplierDirection = $operation->supplier_direction ?? OperationSupplierDirection::IntermediaryPaysSupplier;

        if ($supplierDirection === OperationSupplierDirection::SupplierPaysIntermediary) {
            $this->obligationService->openReceivable(
                operation: $operation,
                counterparty: $operation->supplier()->firstOrFail(),
                creator: $user,
                reason: OperationObligationReason::SupplierPrincipal,
                amount: $supplierAmount,
                currency: $supplierCurrency,
                exchangeRate: $supplierExchangeRate,
                counterpartyRole: OperationCounterpartyRole::Supplier
            );
        } else {
            $this->obligationService->openPayable(
                operation: $operation,
                counterparty: $operation->supplier()->firstOrFail(),
                creator: $user,
                reason: OperationObligationReason::SupplierSettlement,
                amount: $supplierAmount,
                currency: $supplierCurrency,
                exchangeRate: $supplierExchangeRate,
                counterpartyRole: OperationCounterpartyRole::Supplier
            );
        }

        $operation->update([
            'supplier_fulfillment_status' => OperationSupplierFulfillmentStatus::Completed->value,
            'supplier_fulfilled_at' => $operation->supplier_fulfilled_at ?? now(),
        ]);
    }

    private function supplierAmount(Operation $operation): float
    {
        $amount = round((float) $operation->supplier_amount, 4);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'supplier_amount' => 'مبلغ المورد مطلوب لتسجيل تنفيذ المورد.',
            ]);
        }

        return $amount;
    }

    private function supplierCurrency(Operation $operation): string
    {
        $currency = mb_strtoupper(trim((string) $operation->supplier_currency));

        if ($currency === '') {
            throw ValidationException::withMessages([
                'supplier_currency' => 'عملة المورد مطلوبة لتسجيل تنفيذ المورد.',
            ]);
        }

        return $currency;
    }

    private function supplierExchangeRate(Operation $operation): float
    {
        $exchangeRate = round((float) $operation->supplier_exchange_rate, 8);

        if ($exchangeRate <= 0) {
            throw ValidationException::withMessages([
                'supplier_exchange_rate' => 'سعر صرف المورد مطلوب لتسجيل تنفيذ المورد.',
            ]);
        }

        return $exchangeRate;
    }
}
