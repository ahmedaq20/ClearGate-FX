<?php

namespace Database\Factories;

use App\Enums\BoxType;
use App\Models\Box;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Box>
 */
class BoxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Box',
            'type' => fake()->randomElement(BoxType::cases())->value,
            'current_balance' => fake()->randomFloat(4, 0, 10000),
            'currency' => 'USD',
            'assigned_user_id' => null,
            'status' => 'active',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
