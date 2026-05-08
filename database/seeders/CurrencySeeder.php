<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            ['code' => 'USD', 'name' => 'US Dollar', 'name_ar' => 'دولار أمريكي', 'symbol' => '$', 'rate_to_usd' => '1.000000'],
            ['code' => 'JOD', 'name' => 'Jordanian Dinar', 'name_ar' => 'دينار أردني', 'symbol' => 'د.ا', 'rate_to_usd' => '0.709000'],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'name_ar' => 'ليرة تركية', 'symbol' => '₺', 'rate_to_usd' => '32.000000'],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'name_ar' => 'ريال سعودي', 'symbol' => 'ر.س', 'rate_to_usd' => '3.750000'],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'name_ar' => 'جنيه مصري', 'symbol' => 'ج.م', 'rate_to_usd' => '50.000000'],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'name_ar' => 'درهم إماراتي', 'symbol' => 'د.إ', 'rate_to_usd' => '3.672500'],
            ['code' => 'GBP', 'name' => 'British Pound', 'name_ar' => 'جنيه إسترليني', 'symbol' => '£', 'rate_to_usd' => '0.800000'],
            ['code' => 'EUR', 'name' => 'Euro', 'name_ar' => 'يورو', 'symbol' => '€', 'rate_to_usd' => '0.920000'],
            ['code' => 'ILS', 'name' => 'Israeli New Shekel', 'name_ar' => 'شيكل إسرائيلي', 'symbol' => '₪', 'rate_to_usd' => '3.700000'],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->firstOrCreate(
                ['code' => $currency['code']],
                [
                    'name' => $currency['name'],
                    'name_ar' => $currency['name_ar'],
                    'symbol' => $currency['symbol'],
                    'rate_to_usd' => $currency['rate_to_usd'],
                    'is_active' => true,
                ]
            );
        }
    }
}
