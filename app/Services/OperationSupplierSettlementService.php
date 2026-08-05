<?php

namespace App\Services;

use App\Enums\BoxBalanceOperationType;
use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationSettlementDirection;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierSettlementStatus;
use App\Models\AuditLog;
use App\Models\Box;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationSupplierSettlementService
{
    public function __construct(
        private OperationObligationService $obligationService,
    ) {}

    /**
     * @param  array{amount: mixed, box_id: int, operation_obligation_id?: int|null, settlement_date?: string|null, idempotency_key?: string|null, notes?: string|null}  $data
     */
    public function settle(Operation $operation, User $user, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $user, $data): Operation {
            $lockedOperation = Operation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $lockedOperation->attributesToArray();

            if ($lockedOperation->status === OperationStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن تسوية مورد عملية ملغاة.',
                ]);
            }

            if ($lockedOperation->supplier_id === null) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'لا يمكن تسوية مورد لعملية لا تحتوي على مورد.',
                ]);
            }

            $idempotencyKey = $this->nullableString($data['idempotency_key'] ?? null);

            if ($idempotencyKey !== null) {
                $existingSettlement = OperationSettlement::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('operation_id', $lockedOperation->id)
                    ->where('counterparty_role', OperationCounterpartyRole::Supplier->value)
                    ->lockForUpdate()
                    ->first();

                if ($existingSettlement !== null) {
                    $this->ensureReplayMatches($existingSettlement, $lockedOperation, $data);

                    return $lockedOperation;
                }
            }

            $obligations = $this->openSupplierObligations($lockedOperation, $data['operation_obligation_id'] ?? null);

            if ($obligations->isEmpty()) {
                throw ValidationException::withMessages([
                    'operation_obligation_id' => 'لا يوجد التزام مفتوح للمورد على هذه العملية.',
                ]);
            }

            $totalRemaining = round((float) $obligations->sum('balance_amount'), 4);
            $requestedAmount = round((float) $data['amount'], 4);

            if (abs($requestedAmount - $totalRemaining) > 0.00009) {
                throw ValidationException::withMessages([
                    'amount' => 'يجب تسوية كامل مبلغ تسوية المورد ('.$totalRemaining.') دفعة واحدة.',
                ]);
            }

            $box = Box::query()
                ->whereKey($data['box_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canUseBox($user, $box)) {
                throw ValidationException::withMessages([
                    'box_id' => 'غير مصرح باستخدام هذا الصندوق.',
                ]);
            }

            foreach ($obligations as $obligation) {
                $this->ensureBoxCurrencyMatches($box, $obligation);
            }

            foreach ($obligations as $index => $obligation) {
                $settlement = $this->settlementForObligation($obligation, $user, [
                    'amount' => (float) $obligation->balance_amount,
                    'box_id' => $box->id,
                    'settlement_date' => $data['settlement_date'] ?? now()->toDateString(),
                    'idempotency_key' => $index === 0 ? $idempotencyKey : null,
                    'notes' => $data['notes'] ?? null,
                ]);
                $settlement->update(['box_id' => $box->id]);
                $this->moveBoxBalance($box, $lockedOperation, $settlement, $user, $obligation);
            }

            $this->updateOperationSupplierSettlementStatus($lockedOperation);

            $lockedOperation->refresh();

            AuditLog::record(
                action: 'operation.supplier_settlement.updated',
                model: $lockedOperation,
                userId: $user->id,
                oldValues: $oldValues,
                newValues: $lockedOperation->attributesToArray()
            );

            return $lockedOperation;
        }, attempts: 3);
    }

    /**
     * @return Collection<int, OperationObligation>
     */
    private function openSupplierObligations(Operation $operation, mixed $operationObligationId): Collection
    {
        $query = OperationObligation::query()
            ->where('operation_id', $operation->id)
            ->where('counterparty_id', $operation->supplier_id)
            ->where('counterparty_role', OperationCounterpartyRole::Supplier->value)
            ->whereIn('status', [
                OperationObligationStatus::Open->value,
                OperationObligationStatus::PartiallySettled->value,
            ])
            ->lockForUpdate();

        if ($operationObligationId !== null && $operationObligationId !== '') {
            $query->whereKey((int) $operationObligationId);
        }

        return $query
            ->orderByRaw(
                'CASE reason WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [
                    OperationObligationReason::SupplierPrincipal->value,
                    OperationObligationReason::SupplierSettlement->value,
                    OperationObligationReason::Commission->value,
                ]
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{amount: mixed, settlement_date?: string|null, idempotency_key?: string|null, notes?: string|null}  $data
     */
    private function settlementForObligation(OperationObligation $obligation, User $user, array $data): OperationSettlement
    {
        if ($obligation->status === OperationObligationStatus::Settled) {
            $settlement = $this->existingObligationSettlementForKey(
                $data['idempotency_key'] ?? null,
                $obligation,
                $data['amount'],
                $data['box_id']
            );

            if ($settlement !== null) {
                return $settlement;
            }

            throw ValidationException::withMessages([
                'operation_obligation_id' => 'التزام المورد المحدد مسوى بالكامل.',
            ]);
        }

        return $this->settleObligation($obligation, $user, $data);
    }

    /**
     * @param  array{amount: mixed, settlement_date?: string|null, idempotency_key?: string|null, notes?: string|null}  $data
     */
    private function settleObligation(OperationObligation $obligation, User $user, array $data): OperationSettlement
    {
        return $this->obligationService->settle($obligation, $user, [
            'amount' => $data['amount'],
            'currency' => $obligation->currency,
            'exchange_rate' => $obligation->exchange_rate,
            'direction' => $this->settlementDirection($obligation),
            'settlement_date' => $data['settlement_date'] ?? now()->toDateString(),
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function existingObligationSettlementForKey(mixed $idempotencyKey, OperationObligation $obligation, mixed $amount, mixed $boxId): ?OperationSettlement
    {
        if ($idempotencyKey === null || trim((string) $idempotencyKey) === '') {
            return null;
        }

        $settlement = OperationSettlement::query()
            ->where('idempotency_key', trim((string) $idempotencyKey))
            ->lockForUpdate()
            ->first();

        if ($settlement === null) {
            return null;
        }

        if (
            (int) $settlement->operation_obligation_id !== (int) $obligation->id
            || round((float) $settlement->amount, 4) !== round((float) $amount, 4)
            || $settlement->currency !== $obligation->currency
            || ($settlement->box_id !== null && (int) $settlement->box_id !== (int) $boxId)
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'مفتاح التكرار مستخدم لتسوية مختلفة.',
            ]);
        }

        return $settlement;
    }

    private function ensureReplayMatches(OperationSettlement $settlement, Operation $operation, array $data): void
    {
        if (
            (int) $settlement->operation_id !== (int) $operation->id
            || (int) $settlement->counterparty_id !== (int) $operation->supplier_id
            || $settlement->counterparty_role !== OperationCounterpartyRole::Supplier
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'مفتاح التكرار مستخدم لتسوية مختلفة.',
            ]);
        }

        if ($settlement->box_id !== null && (int) $settlement->box_id !== (int) $data['box_id']) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'مفتاح التكرار مستخدم لتسوية مختلفة.',
            ]);
        }
    }

    private function moveBoxBalance(
        Box $box,
        Operation $operation,
        OperationSettlement $settlement,
        User $user,
        OperationObligation $obligation,
    ): void {
        if ($settlement->boxBalanceLogs()->exists()) {
            return;
        }

        $amount = round((float) $settlement->amount, 4);
        $operationType = $this->boxOperationType($obligation);
        $balanceBefore = round((float) $box->current_balance, 4);
        $balanceAfter = $operationType === BoxBalanceOperationType::Add
            ? round($balanceBefore + $amount, 4)
            : round($balanceBefore - $amount, 4);

        if ($balanceAfter < 0) {
            throw ValidationException::withMessages([
                'box_id' => 'رصيد الصندوق غير كافٍ لتسوية المورد.',
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
            'reason' => 'supplier_settlement',
            'notes' => "تسوية المورد للعملية {$operation->reference_number}",
            'created_by' => $user->id,
        ]);
    }

    private function updateOperationSupplierSettlementStatus(Operation $operation): void
    {
        $supplierObligations = OperationObligation::query()
            ->where('operation_id', $operation->id)
            ->where('counterparty_id', $operation->supplier_id)
            ->where('counterparty_role', OperationCounterpartyRole::Supplier->value)
            ->get();

        if ($supplierObligations->isEmpty()) {
            $operation->update([
                'supplier_settlement_status' => OperationSupplierSettlementStatus::Unsettled->value,
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

    private function ensureBoxCurrencyMatches(Box $box, OperationObligation $obligation): void
    {
        if (mb_strtoupper((string) $box->currency) !== mb_strtoupper((string) $obligation->currency)) {
            throw ValidationException::withMessages([
                'box_id' => 'عملة الصندوق يجب أن تطابق عملة تسوية المورد.',
            ]);
        }
    }

    private function settlementDirection(OperationObligation $obligation): OperationSettlementDirection
    {
        return $obligation->type === OperationObligationType::Payable
            ? OperationSettlementDirection::CashOut
            : OperationSettlementDirection::CashIn;
    }

    private function boxOperationType(OperationObligation $obligation): BoxBalanceOperationType
    {
        return $obligation->type === OperationObligationType::Payable
            ? BoxBalanceOperationType::Subtract
            : BoxBalanceOperationType::Add;
    }

    private function canUseBox(User $user, Box $box): bool
    {
        if ($user->hasAnyRole(['owner', 'admin'], 'sanctum')) {
            return true;
        }

        if ($user->hasRole('manager', 'sanctum') && $user->can('box.adjustBalance')) {
            return true;
        }

        return $user->hasRole('operations_employee', 'sanctum')
            && (int) $box->assigned_user_id === (int) $user->id;
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
