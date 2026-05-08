<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
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
        DB::transaction(function () use ($code, $rate, $userId, $date): void {
            Currency::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->firstOrFail()
                ->update(['rate_to_usd' => $rate]);

            ExchangeRate::query()->create([
                'currency_code' => $code,
                'rate' => $rate,
                'source' => 'manual',
                'date' => $date ?? now()->toDateString(),
                'created_by' => $userId,
            ]);

            Cache::forget("rate.{$code}");
        }, attempts: 3);
    }

    public function calculateUsdValue(float $amount, float $rate): float
    {
        if ($rate <= 0) {
            throw new InvalidArgumentException('Exchange rate must be greater than zero.');
        }

        return round($amount / $rate, 4);
    }
}
