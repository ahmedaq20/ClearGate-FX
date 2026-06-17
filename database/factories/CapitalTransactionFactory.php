<?php

namespace Database\Factories;

use App\Models\CapitalAccount;
use App\Models\CapitalTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapitalTransaction>
 */
class CapitalTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'capital_account_id' => CapitalAccount::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['deposit', 'withdraw', 'expense', 'box_transfer']),
            'amount' => fake()->randomFloat(4, 10, 1000),
            'balance_before' => fake()->randomFloat(4, 1000, 10000),
            'balance_after' => fake()->randomFloat(4, 1000, 10000),
            'transaction_date' => now()->toDateString(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
