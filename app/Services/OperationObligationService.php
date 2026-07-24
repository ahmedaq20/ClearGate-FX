<?php

namespace App\Services;

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationSettlementDirection;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ValueError;

class OperationObligationService
{
    public function openReceivable(
        Operation $operation,
        Customer $counterparty,
        User $creator,
        OperationObligationReason $reason,
        float $amount,
        string $currency,
        ?float $exchangeRate = null,
        OperationCounterpartyRole $counterpartyRole = OperationCounterpartyRole::Customer,
    ): OperationObligation {
        return $this->openObligation(
            operation: $operation,
            counterparty: $counterparty,
            creator: $creator,
            counterpartyRole: $counterpartyRole,
            type: OperationObligationType::Receivable,
            reason: $reason,
            amount: $amount,
            currency: $currency,
            exchangeRate: $exchangeRate
        );
    }

    public function openPayable(
        Operation $operation,
        Customer $counterparty,
        User $creator,
        OperationObligationReason $reason,
        float $amount,
        string $currency,
        ?float $exchangeRate = null,
        OperationCounterpartyRole $counterpartyRole = OperationCounterpartyRole::Supplier,
    ): OperationObligation {
        return $this->openObligation(
            operation: $operation,
            counterparty: $counterparty,
            creator: $creator,
            counterpartyRole: $counterpartyRole,
            type: OperationObligationType::Payable,
            reason: $reason,
            amount: $amount,
            currency: $currency,
            exchangeRate: $exchangeRate
        );
    }

    /**
     * @param  array{amount: mixed, currency?: string|null, exchange_rate?: mixed|null, direction: string|OperationSettlementDirection, settlement_date?: string|null, idempotency_key?: string|null, notes?: string|null}  $data
     */
    public function settle(OperationObligation $obligation, User $creator, array $data): OperationSettlement
    {
        return DB::transaction(function () use ($obligation, $creator, $data): OperationSettlement {
            $lockedObligation = OperationObligation::query()
                ->whereKey($obligation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedObligation->status === OperationObligationStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'obligation' => 'لا يمكن تسوية التزام ملغى.',
                ]);
            }

            if ($lockedObligation->status === OperationObligationStatus::Settled) {
                throw ValidationException::withMessages([
                    'obligation' => 'الالتزام مسوى بالكامل.',
                ]);
            }

            $amount = $this->positiveAmount($data['amount']);
            $currency = $this->normalizeCurrency((string) ($data['currency'] ?? $lockedObligation->currency));

            if ($currency !== $lockedObligation->currency) {
                throw ValidationException::withMessages([
                    'currency' => 'عملة التسوية يجب أن تطابق عملة الالتزام في هذه المرحلة.',
                ]);
            }

            if ($amount > round((float) $lockedObligation->balance_amount, 4)) {
                throw ValidationException::withMessages([
                    'amount' => 'مبلغ التسوية أكبر من الرصيد المتبقي للالتزام.',
                ]);
            }

            $idempotencyKey = $this->nullableString($data['idempotency_key'] ?? null);

            if ($idempotencyKey !== null) {
                $existingSettlement = OperationSettlement::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingSettlement !== null) {
                    $this->ensureSettlementMatches($existingSettlement, $lockedObligation, $amount, $currency);

                    return $existingSettlement;
                }
            }

            $settlement = OperationSettlement::query()->create([
                'operation_id' => $lockedObligation->operation_id,
                'operation_obligation_id' => $lockedObligation->id,
                'counterparty_id' => $lockedObligation->counterparty_id,
                'counterparty_role' => $lockedObligation->counterparty_role->value,
                'direction' => $this->settlementDirection($data['direction'])->value,
                'amount' => $amount,
                'currency' => $currency,
                'exchange_rate' => $this->nullablePositiveExchangeRate($data['exchange_rate'] ?? $lockedObligation->exchange_rate),
                'settlement_date' => (string) ($data['settlement_date'] ?? now()->toDateString()),
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]);

            $settledAmount = round((float) $lockedObligation->settled_amount + $amount, 4);
            $balanceAmount = round((float) $lockedObligation->amount - $settledAmount, 4);

            $lockedObligation->update([
                'settled_amount' => $settledAmount,
                'balance_amount' => $balanceAmount,
                'status' => $balanceAmount <= 0
                    ? OperationObligationStatus::Settled->value
                    : OperationObligationStatus::PartiallySettled->value,
            ]);

            return $settlement;
        }, attempts: 3);
    }

    private function openObligation(
        Operation $operation,
        Customer $counterparty,
        User $creator,
        OperationCounterpartyRole $counterpartyRole,
        OperationObligationType $type,
        OperationObligationReason $reason,
        float $amount,
        string $currency,
        ?float $exchangeRate,
    ): OperationObligation {
        return DB::transaction(function () use ($operation, $counterparty, $creator, $counterpartyRole, $type, $reason, $amount, $currency, $exchangeRate): OperationObligation {
            $lockedOperation = Operation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedCounterparty = Customer::query()
                ->whereKey($counterparty->id)
                ->lockForUpdate()
                ->firstOrFail();
            $amount = $this->positiveAmount($amount);
            $currency = $this->normalizeCurrency($currency);
            $exchangeRate = $this->nullablePositiveExchangeRate($exchangeRate);

            $existingObligation = OperationObligation::query()
                ->where('operation_id', $lockedOperation->id)
                ->where('counterparty_id', $lockedCounterparty->id)
                ->where('counterparty_role', $counterpartyRole->value)
                ->where('type', $type->value)
                ->where('reason', $reason->value)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if ($existingObligation !== null) {
                $this->ensureObligationMatches($existingObligation, $amount, $exchangeRate);

                return $existingObligation;
            }

            return OperationObligation::query()->create([
                'operation_id' => $lockedOperation->id,
                'counterparty_id' => $lockedCounterparty->id,
                'counterparty_role' => $counterpartyRole->value,
                'type' => $type->value,
                'reason' => $reason->value,
                'amount' => $amount,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'settled_amount' => 0,
                'balance_amount' => $amount,
                'status' => OperationObligationStatus::Open->value,
                'created_by' => $creator->id,
            ]);
        }, attempts: 3);
    }

    private function positiveAmount(mixed $amount): float
    {
        $amount = round((float) $amount, 4);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'المبلغ يجب أن يكون أكبر من صفر.',
            ]);
        }

        return $amount;
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = mb_strtoupper(trim($currency));

        if ($currency === '') {
            throw ValidationException::withMessages([
                'currency' => 'العملة مطلوبة.',
            ]);
        }

        return $currency;
    }

    private function nullablePositiveExchangeRate(mixed $exchangeRate): ?float
    {
        if ($exchangeRate === null || $exchangeRate === '') {
            return null;
        }

        $exchangeRate = round((float) $exchangeRate, 8);

        if ($exchangeRate <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'سعر الصرف يجب أن يكون أكبر من صفر.',
            ]);
        }

        return $exchangeRate;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function settlementDirection(string|OperationSettlementDirection $direction): OperationSettlementDirection
    {
        if ($direction instanceof OperationSettlementDirection) {
            return $direction;
        }

        try {
            return OperationSettlementDirection::from($direction);
        } catch (ValueError) {
            throw ValidationException::withMessages([
                'direction' => 'اتجاه التسوية غير صالح.',
            ]);
        }
    }

    private function ensureObligationMatches(OperationObligation $obligation, float $amount, ?float $exchangeRate): void
    {
        if (
            round((float) $obligation->amount, 4) !== $amount
            || $this->roundNullableExchangeRate($obligation->exchange_rate) !== $this->roundNullableExchangeRate($exchangeRate)
        ) {
            throw ValidationException::withMessages([
                'obligation' => 'يوجد التزام مالي مختلف لنفس العملية والطرف والسبب.',
            ]);
        }
    }

    private function ensureSettlementMatches(
        OperationSettlement $settlement,
        OperationObligation $obligation,
        float $amount,
        string $currency,
    ): void {
        if (
            (int) $settlement->operation_obligation_id !== (int) $obligation->id
            || round((float) $settlement->amount, 4) !== $amount
            || $settlement->currency !== $currency
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'مفتاح التكرار مستخدم لتسوية مختلفة.',
            ]);
        }
    }

    private function roundNullableExchangeRate(mixed $exchangeRate): ?float
    {
        if ($exchangeRate === null || $exchangeRate === '') {
            return null;
        }

        return round((float) $exchangeRate, 8);
    }
}
