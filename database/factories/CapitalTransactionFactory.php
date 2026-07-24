<?php

namespace Database\Factories;

use App\Enums\CapitalMovementType;
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
            'created_by' => User::factory(),
            'type' => fake()->randomElement(array_map(
                fn (CapitalMovementType $type): string => $type->value,
                CapitalMovementType::cases()
            )),
            'amount' => fake()->randomFloat(4, 10, 1000),
            'currency' => 'USD',
            'balance_before' => fake()->randomFloat(4, 1000, 10000),
            'balance_after' => fake()->randomFloat(4, 1000, 10000),
            'total_balance_before' => fake()->randomFloat(4, 1000, 10000),
            'total_balance_after' => fake()->randomFloat(4, 1000, 10000),
            'transaction_date' => now()->toDateString(),
            'transaction_at' => now(),
            'statement' => fake()->optional()->sentence(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
