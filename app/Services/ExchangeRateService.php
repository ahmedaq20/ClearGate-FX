<?php

namespace App\Services;

use App\Events\ExchangeRateUpdated;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExchangeRateService
{
    public function getRate(string $currencyCode): float
    {
        return (float) Cache::remember(
            "rate.{$currencyCode}",
            3600,
            fn (): string => Currency::query()
                ->where('code', $currencyCode)
                ->where('is_active', true)
                ->value('rate_to_usd') ?? throw (new ModelNotFoundException)->setModel(Currency::class)
        );
    }

    public function updateRate(string $code, float $rate, int $userId, ?string $date = null): void
    {
        [$exchangeRate, $oldRate] = DB::transaction(function () use ($code, $rate, $userId, $date): array {
            $currency = Currency::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->firstOrFail();

            $oldRate = (float) $currency->rate_to_usd;
            $currency->update(['rate_to_usd' => $rate]);

            $exchangeRate = ExchangeRate::query()->create([
                'currency_code' => $code,
                'rate' => $rate,
                'source' => 'manual',
                'date' => $date ?? now()->toDateString(),
                'created_by' => $userId,
            ]);

            Cache::forget("rate.{$code}");

            return [$exchangeRate, $oldRate];
        }, attempts: 3);

        event(new ExchangeRateUpdated($exchangeRate, User::query()->findOrFail($userId), $oldRate));
    }

    public function calculateUsdValue(float $amount, float $rate): float
    {
        if ($rate <= 0) {
            throw new InvalidArgumentException('Exchange rate must be greater than zero.');
        }

        return round($amount / $rate, 4);
    }
}
