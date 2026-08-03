<?php

namespace App\Services;

use App\Enums\BoxBalanceOperationType;
use App\Enums\OperationCommissionPayer;
use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationCustomerDirection;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierDirection;
use App\Enums\OperationSupplierSettlementStatus;
use App\Models\AuditLog;
use App\Models\Box;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationService
{
    public function __construct(private readonly OperationObligationService $operationObligationService) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, User $user): Operation
    {
        return DB::transaction(function () use ($data, $user): Operation {
            $operation = Operation::query()->create($this->operationPayload($data, $user));

            $this->applyBoxFunding($operation, $user, BoxBalanceOperationType::Subtract);
            $this->applySupplierFunding($operation);
            $this->syncSupplierCommissionObligation($operation, $user);

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

            $this->ensureDirectionsCanChange($lockedOperation, $payload);
            $this->applyBoxFunding($lockedOperation, $user, BoxBalanceOperationType::Add);
            $this->reverseSupplierFunding($lockedOperation);

            $lockedOperation->update($payload);
            $lockedOperation->refresh();

            $this->applyBoxFunding($lockedOperation, $user, BoxBalanceOperationType::Subtract);
            $this->applySupplierFunding($lockedOperation);
            $this->syncSupplierCommissionObligation($lockedOperation, $user);

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
            $this->removeSupplierCommissionObligation($lockedOperation);

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

            $this->removeSupplierCommissionObligation($lockedOperation);

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
        $supplierDirection = $isSupplierFunded
            ? $this->resolveSupplierDirection($data, $operation)
            : null;
        $customerAmount = round((float) $data['customer_amount'], 4);
        $commissionAmount = $this->calculateCommissionAmount(
            $customerAmount,
            (string) $data['commission_type'],
            (float) $data['commission_rate']
        );
        $commissionPayer = $this->resolveCommissionPayer($data, $operation);

        if (! $isSupplierFunded && in_array($commissionPayer, [OperationCommissionPayer::Supplier, OperationCommissionPayer::Both], true)) {
            throw ValidationException::withMessages([
                'commission_payer' => 'لا يمكن تحميل المورد عمولة بدون اختيار مورد للعملية.',
            ]);
        }

        [$customerCommissionAmount, $supplierCommissionAmount] = $this->resolveCommissionSplit($commissionAmount, $commissionPayer, $data);

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
            'commission_payer' => $commissionPayer->value,
            'commission_amount' => $commissionAmount,
            'customer_commission_amount' => $customerCommissionAmount,
            'supplier_commission_amount' => $supplierCommissionAmount,
            'customer_net_amount' => round($customerAmount - $customerCommissionAmount, 4),
            'commission_currency' => $data['customer_currency'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $operation?->created_by ?? $user->id,
        ];

        if ($isSupplierFunded && $supplierDirection !== null) {
            $payload['supplier_direction'] = $supplierDirection->value;
            $payload['customer_direction'] = $this->customerDirectionForSupplier($supplierDirection)->value;
        }

        if (! $isSupplierFunded) {
            $payload['supplier_direction'] = null;
            $payload['customer_direction'] = null;
        }

        if ($operation === null) {
            $status = isset($data['box_id']) && $data['box_id'] !== null
                ? OperationStatus::Completed
                : OperationStatus::from((string) $data['status']);

            $payload['status'] = $status->value;
            $payload['completed_at'] = $status === OperationStatus::Completed ? now() : null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCommissionPayer(array $data, ?Operation $operation): OperationCommissionPayer
    {
        $commissionPayer = $data['commission_payer'] ?? $operation?->commission_payer ?? OperationCommissionPayer::Customer;

        if ($commissionPayer instanceof OperationCommissionPayer) {
            return $commissionPayer;
        }

        if ($commissionPayer === null || $commissionPayer === '') {
            return OperationCommissionPayer::Customer;
        }

        return OperationCommissionPayer::from((string) $commissionPayer);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: float, 1: float}
     */
    private function resolveCommissionSplit(float $commissionAmount, OperationCommissionPayer $commissionPayer, array $data): array
    {
        if ($commissionPayer === OperationCommissionPayer::Customer) {
            return [$commissionAmount, 0.0];
        }

        if ($commissionPayer === OperationCommissionPayer::Supplier) {
            return [0.0, $commissionAmount];
        }

        if (! array_key_exists('customer_commission_amount', $data) || ! array_key_exists('supplier_commission_amount', $data)) {
            throw ValidationException::withMessages([
                'commission_split' => 'يجب تحديد عمولة العميل وعمولة المورد عند توزيع العمولة على الطرفين.',
            ]);
        }

        $customerCommissionAmount = round((float) $data['customer_commission_amount'], 4);
        $supplierCommissionAmount = round((float) $data['supplier_commission_amount'], 4);

        if ($customerCommissionAmount < 0 || $supplierCommissionAmount < 0) {
            throw ValidationException::withMessages([
                'commission_split' => 'قيم توزيع العمولة يجب أن تكون أكبر من أو تساوي صفر.',
            ]);
        }

        if (abs(round($customerCommissionAmount + $supplierCommissionAmount, 4) - $commissionAmount) > 0.00009) {
            throw ValidationException::withMessages([
                'commission_split' => 'مجموع عمولة العميل والمورد يجب أن يساوي إجمالي العمولة.',
            ]);
        }

        return [$customerCommissionAmount, $supplierCommissionAmount];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSupplierDirection(array $data, ?Operation $operation): ?OperationSupplierDirection
    {
        $direction = $data['supplier_direction'] ?? $operation?->supplier_direction;

        if ($direction instanceof OperationSupplierDirection) {
            return $direction;
        }

        if ($direction !== null && $direction !== '') {
            return OperationSupplierDirection::from((string) $direction);
        }

        if ($operation !== null) {
            return null;
        }

        return OperationSupplierDirection::SupplierPaysIntermediary;
    }

    private function customerDirectionForSupplier(OperationSupplierDirection $supplierDirection): OperationCustomerDirection
    {
        return $supplierDirection === OperationSupplierDirection::SupplierPaysIntermediary
            ? OperationCustomerDirection::IntermediaryPaysCustomer
            : OperationCustomerDirection::CustomerPaysIntermediary;
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

    private function syncSupplierCommissionObligation(Operation $operation, User $user): void
    {
        $existingObligation = $this->supplierCommissionObligation($operation);
        $supplierCommissionAmount = round((float) $operation->supplier_commission_amount, 4);

        if ($operation->supplier_id === null || $supplierCommissionAmount <= 0) {
            $this->deleteUnsettledSupplierCommissionObligation($existingObligation);
            $this->refreshSupplierSettlementStatus($operation);

            return;
        }

        if ($existingObligation !== null) {
            $this->ensureSupplierCommissionCanChange($existingObligation, $operation);

            if ((float) $existingObligation->settled_amount > 0 || $existingObligation->settlements()->exists()) {
                $this->refreshSupplierSettlementStatus($operation);

                return;
            }

            $existingObligation->update([
                'counterparty_id' => $operation->supplier_id,
                'amount' => $supplierCommissionAmount,
                'currency' => $operation->commission_currency,
                'exchange_rate' => $operation->customer_exchange_rate,
                'settled_amount' => 0,
                'balance_amount' => $supplierCommissionAmount,
                'status' => OperationObligationStatus::Open->value,
            ]);
            $this->refreshSupplierSettlementStatus($operation);

            return;
        }

        $supplier = $operation->supplier()->lockForUpdate()->firstOrFail();

        $this->operationObligationService->openReceivable(
            operation: $operation,
            counterparty: $supplier,
            creator: $user,
            reason: OperationObligationReason::Commission,
            amount: $supplierCommissionAmount,
            currency: (string) $operation->commission_currency,
            exchangeRate: (float) $operation->customer_exchange_rate,
            counterpartyRole: OperationCounterpartyRole::Supplier
        );
        $this->refreshSupplierSettlementStatus($operation);
    }

    private function removeSupplierCommissionObligation(Operation $operation): void
    {
        $this->deleteUnsettledSupplierCommissionObligation($this->supplierCommissionObligation($operation));
        $this->refreshSupplierSettlementStatus($operation);
    }

    private function supplierCommissionObligation(Operation $operation): ?OperationObligation
    {
        return OperationObligation::query()
            ->where('operation_id', $operation->id)
            ->where('counterparty_role', OperationCounterpartyRole::Supplier->value)
            ->where('type', OperationObligationType::Receivable->value)
            ->where('reason', OperationObligationReason::Commission->value)
            ->lockForUpdate()
            ->first();
    }

    private function ensureSupplierCommissionCanChange(OperationObligation $obligation, Operation $operation): void
    {
        if ((float) $obligation->settled_amount <= 0 && ! $obligation->settlements()->exists()) {
            return;
        }

        $sameCommission = (int) $obligation->counterparty_id === (int) $operation->supplier_id
            && abs(round((float) $obligation->amount, 4) - round((float) $operation->supplier_commission_amount, 4)) <= 0.00009
            && $obligation->currency === $operation->commission_currency
            && abs(round((float) $obligation->exchange_rate, 8) - round((float) $operation->customer_exchange_rate, 8)) <= 0.000000009;

        if ($sameCommission) {
            return;
        }

        throw ValidationException::withMessages([
            'commission_payer' => 'لا يمكن تغيير عمولة المورد بعد بدء تسويتها.',
        ]);
    }

    private function deleteUnsettledSupplierCommissionObligation(?OperationObligation $obligation): void
    {
        if ($obligation === null) {
            return;
        }

        if ((float) $obligation->settled_amount > 0 || $obligation->settlements()->exists()) {
            throw ValidationException::withMessages([
                'commission_payer' => 'لا يمكن إزالة عمولة المورد بعد بدء تسويتها.',
            ]);
        }

        $obligation->delete();
    }

    private function refreshSupplierSettlementStatus(Operation $operation): void
    {
        if ($operation->supplier_id === null) {
            $operation->update([
                'supplier_settlement_status' => null,
                'supplier_settled_at' => null,
            ]);

            return;
        }

        $supplierObligations = OperationObligation::query()
            ->where('operation_id', $operation->id)
            ->where('counterparty_id', $operation->supplier_id)
            ->where('counterparty_role', OperationCounterpartyRole::Supplier->value)
            ->get();

        if ($supplierObligations->isEmpty()) {
            $operation->update([
                'supplier_settlement_status' => null,
                'supplier_settled_at' => null,
            ]);

            return;
        }

        $hasSettledAmount = $supplierObligations->contains(
            fn (OperationObligation $obligation): bool => round((float) $obligation->settled_amount, 4) > 0
        );
        $allSettled = $supplierObligations->every(
            fn (OperationObligation $obligation): bool => $obligation->status === OperationObligationStatus::Settled
        );

        $operation->update([
            'supplier_settlement_status' => $allSettled
                ? OperationSupplierSettlementStatus::Settled->value
                : ($hasSettledAmount
                    ? OperationSupplierSettlementStatus::PartiallySettled->value
                    : OperationSupplierSettlementStatus::Unsettled->value),
            'supplier_settled_at' => $allSettled ? now() : null,
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
            'supplier_direction',
            'customer_currency',
            'customer_amount',
            'customer_exchange_rate',
            'commission_type',
            'commission_rate',
            'commission_payer',
            'customer_commission_amount',
            'supplier_commission_amount',
            'notes',
        ]), $data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensureDirectionsCanChange(Operation $operation, array $payload): void
    {
        if (
            $this->directionValue($operation->supplier_direction) === $this->directionValue($payload['supplier_direction'] ?? null)
            && $this->directionValue($operation->customer_direction) === $this->directionValue($payload['customer_direction'] ?? null)
        ) {
            return;
        }

        if (
            $operation->customer_settlement_status !== null
            || $operation->supplier_fulfillment_status !== null
            || $operation->obligations()->exists()
            || $operation->settlements()->exists()
        ) {
            throw ValidationException::withMessages([
                'supplier_direction' => 'لا يمكن تغيير اتجاه العملية بعد بدء التنفيذ أو التسوية.',
            ]);
        }
    }

    private function directionValue(mixed $direction): ?string
    {
        if ($direction instanceof OperationCustomerDirection || $direction instanceof OperationSupplierDirection) {
            return $direction->value;
        }

        return $direction === null || $direction === '' ? null : (string) $direction;
    }
}
