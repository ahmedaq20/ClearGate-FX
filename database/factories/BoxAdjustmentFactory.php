<?php

namespace Database\Factories;

use App\Models\Box;
use App\Models\BoxAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoxAdjustment>
 */
class BoxAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $balanceBefore = fake()->randomFloat(4, 500, 10000);
        $amount = fake()->randomFloat(4, 1, 500);
        $type = fake()->randomElement(['increase', 'decrease']);

        return [
            'box_id' => Box::factory(),
            'adjustment_type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $type === 'increase'
                ? round($balanceBefore + $amount, 4)
                : round($balanceBefore - $amount, 4),
            'reason' => fake()->sentence(3),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
