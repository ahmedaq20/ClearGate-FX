<?php

namespace App\Services;

use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionRestored;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * @param  array{
     *     type: string,
     *     amount: int|float|string,
     *     currency_code: string,
     *     exchange_rate?: int|float|string|null,
     *     customer_id?: int|null,
     *     commission_type?: string|null,
     *     commission_rate?: int|float|string|null,
     *     commission_sign?: int|null,
     *     note?: string|null,
     *     reference_number?: string|null,
     *     country?: string|null,
     *     transaction_date: string
     * }  $data
     */
    public function store(array $data, User $user): Transaction
    {
        $exchangeRate = isset($data['exchange_rate']) && $data['exchange_rate'] !== null
            ? (float) $data['exchange_rate']
            : $this->exchangeRateService->getRate($data['currency_code']);

        $usdValue = $this->exchangeRateService->calculateUsdValue((float) $data['amount'], $exchangeRate);
        $commissionUsd = $this->calculateCommissionUsd(
            $usdValue,
            $data['commission_type'] ?? null,
            isset($data['commission_rate']) ? (float) $data['commission_rate'] : null
        );
        $commissionSign = $this->resolveCommissionSign($data['commission_type'] ?? null, $data['commission_sign'] ?? null);
        $netUsdValue = $this->calculateNetUsdValue($usdValue, $commissionUsd, $commissionSign);
        $direction = $this->resolveDirection($data['type']);

        $transaction = DB::transaction(function () use ($data, $user, $exchangeRate, $usdValue, $commissionUsd, $commissionSign, $netUsdValue, $direction): Transaction {
            $vault = Vault::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $customer = $this->resolveCustomer($data['customer_id'] ?? null, $user);

            if ($direction === -1) {
                $this->ensureSufficientBalance($vault, $customer, $netUsdValue);
            }

            $transaction = Transaction::query()->create([
                'user_id' => $user->id,
                'vault_id' => $vault->id,
                'customer_id' => $customer?->id,
                'type' => $data['type'],
                'amount' => (float) $data['amount'],
                'currency_code' => $data['currency_code'],
                'exchange_rate' => $exchangeRate,
                'usd_value' => $usdValue,
                'commission_type' => $data['commission_type'] ?? null,
                'commission_rate' => $data['commission_rate'] ?? null,
                'commission_sign' => $commissionSign,
                'commission_usd' => $commissionUsd,
                'net_usd_value' => $netUsdValue,
                'direction' => $direction,
                'note' => $data['note'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'country' => $data['country'] ?? null,
                'transaction_date' => $data['transaction_date'],
            ]);

            $this->applyBalanceEffect($vault, $customer, $netUsdValue, $direction);

            return $transaction;
        }, attempts: 3);

        event(new TransactionCreated($transaction, $user));

        return $transaction;
    }

    public function softDelete(Transaction $transaction, User $user): void
    {
        $deletedTransaction = DB::transaction(function () use ($transaction): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $vault = Vault::query()->whereKey($transaction->vault_id)->lockForUpdate()->firstOrFail();
            $customer = $transaction->customer_id
                ? Customer::query()->whereKey($transaction->customer_id)->lockForUpdate()->first()
                : null;

            $this->applyBalanceEffect($vault, $customer, (float) $transaction->net_usd_value, (int) $transaction->direction * -1);

            $transaction->delete();

            return $transaction;
        }, attempts: 3);

        event(new TransactionDeleted($deletedTransaction, $user));
    }

    public function restore(int $id, ?User $actor = null): Transaction
    {
        $transaction = DB::transaction(function () use ($id): Transaction {
            $transaction = Transaction::withTrashed()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $transaction->trashed()) {
                throw ValidationException::withMessages([
                    'transaction' => 'لا يمكن استعادة عملية غير محذوفة',
                ]);
            }

            $vault = Vault::query()->whereKey($transaction->vault_id)->lockForUpdate()->firstOrFail();
            $customer = $transaction->customer_id
                ? Customer::query()->whereKey($transaction->customer_id)->lockForUpdate()->first()
                : null;

            $transaction->restore();

            $this->applyBalanceEffect($vault, $customer, (float) $transaction->net_usd_value, (int) $transaction->direction);

            return $transaction;
        }, attempts: 3);

        if ($actor !== null) {
            event(new TransactionRestored($transaction, $actor));
        }

        return $transaction;
    }

    private function calculateCommissionUsd(float $usdValue, ?string $commissionType, ?float $commissionRate): float
    {
        if ($commissionType === null) {
            return 0.0;
        }

        if ($commissionRate === null) {
            throw ValidationException::withMessages([
                'commission_rate' => 'قيمة العمولة مطلوبة عند تحديد نوع العمولة.',
            ]);
        }

        return match ($commissionType) {
            'percentage' => round($usdValue * ($commissionRate / 100), 4),
            'fixed' => round($commissionRate, 4),
            default => throw ValidationException::withMessages([
                'commission_type' => 'نوع العمولة يجب أن يكون نسبة أو قيمة ثابتة.',
            ]),
        };
    }

    private function resolveDirection(string $type): int
    {
        return match ($type) {
            'receive' => 1,
            'send' => -1,
            default => throw ValidationException::withMessages([
                'type' => 'نوع العملية يجب أن يكون استقبال أو إرسال.',
            ]),
        };
    }

    private function resolveCommissionSign(?string $commissionType, mixed $commissionSign): ?int
    {
        if ($commissionType === null) {
            return null;
        }

        $commissionSign = (int) $commissionSign;

        if (! in_array($commissionSign, [1, -1], true)) {
            throw ValidationException::withMessages([
                'commission_sign' => 'إشارة العمولة يجب أن تكون 1 أو -1 عند تحديد نوع العمولة.',
            ]);
        }

        return $commissionSign;
    }

    private function calculateNetUsdValue(float $usdValue, float $commissionUsd, ?int $commissionSign): float
    {
        return round($usdValue + ($commissionUsd * ($commissionSign ?? 0)), 4);
    }

    private function resolveCustomer(?int $customerId, User $user): ?Customer
    {
        if ($customerId === null) {
            return null;
        }

        $customer = Customer::query()
            ->whereKey($customerId)
            ->lockForUpdate()
            ->first();

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => ['العميل المحدد غير موجود'],
            ]);
        }

        if (! $user->isOwner() && $customer->user_id !== $user->id) {
            throw new AuthorizationException('لا يمكنك تنفيذ عملية لهذا العميل لأنه غير تابع لحسابك');
        }

        return $customer;
    }

    private function ensureSufficientBalance(Vault $vault, ?Customer $customer, float $netUsdValue): void
    {
        if ((float) $vault->balance_usd < $netUsdValue) {
            throw ValidationException::withMessages([
                'vault' => 'رصيد الصندوق غير كافٍ.',
            ]);
        }

        if ($customer !== null && (float) $customer->balance_usd < $netUsdValue) {
            throw ValidationException::withMessages([
                'customer' => 'رصيد العميل غير كافٍ.',
            ]);
        }
    }

    private function applyBalanceEffect(Vault $vault, ?Customer $customer, float $netUsdValue, int $direction): void
    {
        $balanceChange = round($netUsdValue * $direction, 4);

        $vault->update([
            'balance_usd' => round((float) $vault->balance_usd + $balanceChange, 4),
        ]);

        if ($customer !== null) {
            $customer->update([
                'balance_usd' => round((float) $customer->balance_usd + $balanceChange, 4),
            ]);
        }
    }
}
