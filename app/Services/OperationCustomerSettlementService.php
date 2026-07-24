<?php

namespace App\Services;

use App\Enums\BoxBalanceOperationType;
use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationCustomerDirection;
use App\Enums\OperationCustomerSettlementStatus;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationType;
use App\Enums\OperationSettlementDirection;
use App\Enums\OperationStatus;
use App\Models\AuditLog;
use App\Models\Box;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationCustomerSettlementService
{
    public function __construct(
        private OperationObligationService $obligationService,
        private OperationStateTransitionService $stateTransitionService,
    ) {}

    /**
     * @param  array{customer_direction: string, customer_settlement_status: string, box_id?: int|null, settlement_date?: string|null, idempotency_key?: string|null, notes?: string|null}  $data
     */
    public function settle(Operation $operation, User $user, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $user, $data): Operation {
            $lockedOperation = Operation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $lockedOperation->attributesToArray();
            $direction = OperationCustomerDirection::from((string) $data['customer_direction']);
            $status = OperationCustomerSettlementStatus::from((string) $data['customer_settlement_status']);

            if ($lockedOperation->status === OperationStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن تسوية عميل عملية ملغاة.',
                ]);
            }

            $this->ensureDirectionCanBeSet($lockedOperation, $direction);

            if ($status === OperationCustomerSettlementStatus::Pending) {
                $this->markPending($lockedOperation, $user, $direction);
            } else {
                $this->markCompleted($lockedOperation, $user, $direction, $data);
            }

            $this->stateTransitionService->sync($lockedOperation);
            $lockedOperation->refresh();

            AuditLog::record(
                action: 'operation.customer_settlement.updated',
                model: $lockedOperation,
                userId: $user->id,
                oldValues: $oldValues,
                newValues: $lockedOperation->attributesToArray()
            );

            return $lockedOperation;
        }, attempts: 3);
    }

    private function markPending(Operation $operation, User $user, OperationCustomerDirection $direction): void
    {
        if ($operation->customer_settlement_status === OperationCustomerSettlementStatus::Completed) {
            throw ValidationException::withMessages([
                'customer_settlement_status' => 'تمت تسوية العميل مسبقاً.',
            ]);
        }

        if ($direction === OperationCustomerDirection::CustomerPaysIntermediary) {
            $this->obligationService->openReceivable(
                operation: $operation,
                counterparty: $operation->customer()->firstOrFail(),
                creator: $user,
                reason: OperationObligationReason::CustomerPrincipal,
                amount: (float) $operation->customer_amount,
                currency: (string) $operation->customer_currency,
                exchangeRate: (float) $operation->customer_exchange_rate,
                counterpartyRole: OperationCounterpartyRole::Customer
            );
        } else {
            $this->obligationService->openPayable(
                operation: $operation,
                counterparty: $operation->customer()->firstOrFail(),
                creator: $user,
                reason: OperationObligationReason::CustomerPrincipal,
                amount: (float) $operation->customer_amount,
                currency: (string) $operation->customer_currency,
                exchangeRate: (float) $operation->customer_exchange_rate,
                counterpartyRole: OperationCounterpartyRole::Customer
            );
        }

        $operation->update([
            'customer_direction' => $direction->value,
            'customer_settlement_status' => OperationCustomerSettlementStatus::Pending->value,
            'customer_settled_at' => null,
        ]);
    }

    /**
     * @param  array{box_id?: int|null, settlement_date?: string|null, idempotency_key?: string|null, notes?: string|null}  $data
     */
    private function markCompleted(Operation $operation, User $user, OperationCustomerDirection $direction, array $data): void
    {
        $idempotencyKey = $this->nullableString($data['idempotency_key'] ?? null);

        if ($operation->customer_settlement_status === OperationCustomerSettlementStatus::Completed) {
            if ($idempotencyKey !== null) {
                $this->existingSettlementForKey($idempotencyKey, $operation, $direction);

                return;
            }

            throw ValidationException::withMessages([
                'customer_settlement_status' => 'تمت تسوية العميل مسبقاً.',
            ]);
        }

        $box = Box::query()
            ->whereKey($data['box_id'] ?? null)
            ->lockForUpdate()
            ->firstOrFail();
        $amount = round((float) $operation->customer_amount, 4);
        $currency = mb_strtoupper((string) $operation->customer_currency);

        if (mb_strtoupper((string) $box->currency) !== $currency) {
            throw ValidationException::withMessages([
                'box_id' => 'عملة الصندوق يجب أن تطابق عملة تسوية العميل.',
            ]);
        }

        $settlement = $this->settlementForCompletion($operation, $user, $direction, $data, $idempotencyKey);
        $settlement->update(['box_id' => $box->id]);
        $this->moveBoxBalance($box, $operation, $settlement, $user, $direction, $amount);

        $operation->update([
            'customer_direction' => $direction->value,
            'customer_settlement_status' => OperationCustomerSettlementStatus::Completed->value,
            'customer_settled_at' => now(),
        ]);
    }

    /**
     * @param  array{settlement_date?: string|null, idempotency_key?: string|null, notes?: string|null}  $data
     */
    private function settlementForCompletion(
        Operation $operation,
        User $user,
        OperationCustomerDirection $direction,
        array $data,
        ?string $idempotencyKey,
    ): OperationSettlement {
        if ($idempotencyKey !== null) {
            $existingSettlement = $this->existingSettlementForKey($idempotencyKey, $operation, $direction);

            if ($existingSettlement !== null) {
                return $existingSettlement;
            }
        }

        $obligation = $this->matchingCustomerObligation($operation, $direction);
        $settlementDirection = $this->settlementDirection($direction);

        if ($obligation !== null) {
            return $this->obligationService->settle($obligation, $user, [
                'amount' => (float) $operation->customer_amount,
                'currency' => (string) $operation->customer_currency,
                'exchange_rate' => (float) $operation->customer_exchange_rate,
                'direction' => $settlementDirection,
                'settlement_date' => $data['settlement_date'] ?? now()->toDateString(),
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
            ]);
        }

        return OperationSettlement::query()->create([
            'operation_id' => $operation->id,
            'operation_obligation_id' => null,
            'counterparty_id' => $operation->customer_id,
            'counterparty_role' => OperationCounterpartyRole::Customer->value,
            'direction' => $settlementDirection->value,
            'amount' => round((float) $operation->customer_amount, 4),
            'currency' => mb_strtoupper((string) $operation->customer_currency),
            'exchange_rate' => round((float) $operation->customer_exchange_rate, 8),
            'settlement_date' => (string) ($data['settlement_date'] ?? now()->toDateString()),
            'idempotency_key' => $idempotencyKey,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    private function matchingCustomerObligation(Operation $operation, OperationCustomerDirection $direction): ?OperationObligation
    {
        $type = $direction === OperationCustomerDirection::CustomerPaysIntermediary
            ? OperationObligationType::Receivable
            : OperationObligationType::Payable;

        return OperationObligation::query()
            ->where('operation_id', $operation->id)
            ->where('counterparty_id', $operation->customer_id)
            ->where('counterparty_role', OperationCounterpartyRole::Customer->value)
            ->where('type', $type->value)
            ->where('reason', OperationObligationReason::CustomerPrincipal->value)
            ->whereIn('status', ['open', 'partially_settled'])
            ->lockForUpdate()
            ->first();
    }

    private function moveBoxBalance(
        Box $box,
        Operation $operation,
        OperationSettlement $settlement,
        User $user,
        OperationCustomerDirection $direction,
        float $amount,
    ): void {
        if ($settlement->boxBalanceLogs()->exists()) {
            return;
        }

        $operationType = $direction === OperationCustomerDirection::CustomerPaysIntermediary
            ? BoxBalanceOperationType::Add
            : BoxBalanceOperationType::Subtract;
        $balanceBefore = round((float) $box->current_balance, 4);
        $balanceAfter = $operationType === BoxBalanceOperationType::Add
            ? round($balanceBefore + $amount, 4)
            : round($balanceBefore - $amount, 4);

        if ($balanceAfter < 0) {
            throw ValidationException::withMessages([
                'box_id' => 'رصيد الصندوق غير كافٍ لتسوية العميل.',
            ]);
        }

        $box->update(['current_balance' => $balanceAfter]);
        $box->balanceLogs()->create([
            'operation_id' => $operation->id,
            'operation_settlement_id' => $settlement->id,
            'operation_type' => $operationType->value,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reason' => 'customer_settlement',
            'notes' => "تسوية العميل للعملية {$operation->reference_number}",
            'created_by' => $user->id,
        ]);
    }

    private function settlementDirection(OperationCustomerDirection $direction): OperationSettlementDirection
    {
        return $direction === OperationCustomerDirection::CustomerPaysIntermediary
            ? OperationSettlementDirection::CashIn
            : OperationSettlementDirection::CashOut;
    }

    private function ensureDirectionCanBeSet(Operation $operation, OperationCustomerDirection $direction): void
    {
        if ($operation->customer_direction !== null && $operation->customer_direction !== $direction) {
            throw ValidationException::withMessages([
                'customer_direction' => 'لا يمكن تغيير اتجاه تسوية العميل بعد تسجيله.',
            ]);
        }
    }

    private function existingSettlementForKey(
        string $idempotencyKey,
        Operation $operation,
        OperationCustomerDirection $direction,
    ): ?OperationSettlement {
        $settlement = OperationSettlement::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($settlement === null) {
            return null;
        }

        if (
            (int) $settlement->operation_id !== (int) $operation->id
            || (int) $settlement->counterparty_id !== (int) $operation->customer_id
            || $settlement->counterparty_role !== OperationCounterpartyRole::Customer
            || $settlement->direction !== $this->settlementDirection($direction)
            || round((float) $settlement->amount, 4) !== round((float) $operation->customer_amount, 4)
            || $settlement->currency !== mb_strtoupper((string) $operation->customer_currency)
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'مفتاح التكرار مستخدم لتسوية مختلفة.',
            ]);
        }

        return $settlement;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
