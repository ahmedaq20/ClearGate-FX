<?php

namespace Database\Factories;

use App\Models\CapitalAccount;
use App\Models\OwnerExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerExpense>
 */
class OwnerExpenseFactory extends Factory
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
            'title' => fake()->sentence(3),
            'category' => fake()->randomElement(['vehicle', 'housing', 'family', 'education', 'medical', 'travel', 'other']),
            'amount' => fake()->randomFloat(4, 10, 1000),
            'expense_date' => now()->toDateString(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
