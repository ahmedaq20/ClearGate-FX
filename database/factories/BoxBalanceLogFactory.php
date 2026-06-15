<?php

namespace Database\Factories;

use App\Enums\BoxBalanceOperationType;
use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoxBalanceLog>
 */
class BoxBalanceLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $balanceBefore = fake()->randomFloat(4, 100, 10000);
        $amount = fake()->randomFloat(4, 1, 100);

        return [
            'box_id' => Box::factory(),
            'operation_type' => BoxBalanceOperationType::Add->value,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceBefore + $amount,
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
