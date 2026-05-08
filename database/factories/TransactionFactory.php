<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory()->create();
        $vault = $user->vault ?? Vault::factory()->create(['user_id' => $user->id]);
        $currency = Currency::query()->first() ?? Currency::factory()->create(['code' => 'USD']);

        return [
            'user_id' => $user->id,
            'vault_id' => $vault->id,
            'customer_id' => null,
            'type' => 'receive',
            'amount' => 100,
            'currency_code' => $currency->code,
            'exchange_rate' => 1,
            'usd_value' => 100,
            'commission_type' => null,
            'commission_rate' => null,
            'commission_sign' => null,
            'commission_usd' => 0,
            'net_usd_value' => 100,
            'direction' => 1,
            'note' => fake()->sentence(),
            'reference_number' => fake()->unique()->bothify('TXN-####'),
            'country' => fake()->country(),
            'transaction_date' => now()->toDateString(),
        ];
    }
}
