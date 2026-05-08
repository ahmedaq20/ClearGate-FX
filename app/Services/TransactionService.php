<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vault;
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

        return DB::transaction(function () use ($data, $user, $exchangeRate, $usdValue, $commissionUsd, $commissionSign, $netUsdValue, $direction): Transaction {
            $vault = Vault::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $customer = $this->resolveCustomer($data['customer_id'] ?? null, $vault);

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
    }

    public function softDelete(Transaction $transaction, User $user): void
    {
        DB::transaction(function () use ($transaction): void {
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
        }, attempts: 3);
    }

    public function restore(int $id): Transaction
    {
        return DB::transaction(function () use ($id): Transaction {
            $transaction = Transaction::withTrashed()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $transaction->trashed()) {
                return $transaction;
            }

            $vault = Vault::query()->whereKey($transaction->vault_id)->lockForUpdate()->firstOrFail();
            $customer = $transaction->customer_id
                ? Customer::query()->whereKey($transaction->customer_id)->lockForUpdate()->first()
                : null;

            $transaction->restore();

            $this->applyBalanceEffect($vault, $customer, (float) $transaction->net_usd_value, (int) $transaction->direction);

            return $transaction;
        }, attempts: 3);
    }

    private function calculateCommissionUsd(float $usdValue, ?string $commissionType, ?float $commissionRate): float
    {
        if ($commissionType === null) {
            return 0.0;
        }

        if ($commissionRate === null) {
            throw ValidationException::withMessages([
                'commission_rate' => 'Commission rate is required when commission type is present.',
            ]);
        }

        return match ($commissionType) {
            'percentage' => round($usdValue * ($commissionRate / 100), 4),
            'fixed' => round($commissionRate, 4),
            default => throw ValidationException::withMessages([
                'commission_type' => 'Commission type must be percentage or fixed.',
            ]),
        };
    }

    private function resolveDirection(string $type): int
    {
        return match ($type) {
            'receive' => 1,
            'send' => -1,
            default => throw ValidationException::withMessages([
                'type' => 'Transaction type must be receive or send.',
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
                'commission_sign' => 'Commission sign must be 1 or -1 when commission type is present.',
            ]);
        }

        return $commissionSign;
    }

    private function calculateNetUsdValue(float $usdValue, float $commissionUsd, ?int $commissionSign): float
    {
        return round($usdValue + ($commissionUsd * ($commissionSign ?? 0)), 4);
    }

    private function resolveCustomer(?int $customerId, Vault $vault): ?Customer
    {
        if ($customerId === null) {
            return null;
        }

        return Customer::query()
            ->whereKey($customerId)
            ->where('vault_id', $vault->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureSufficientBalance(Vault $vault, ?Customer $customer, float $netUsdValue): void
    {
        if ((float) $vault->balance_usd < $netUsdValue) {
            throw ValidationException::withMessages([
                'vault' => 'Vault does not have enough money.',
            ]);
        }

        if ($customer !== null && (float) $customer->balance_usd < $netUsdValue) {
            throw ValidationException::withMessages([
                'customer' => 'Customer does not have enough money.',
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
