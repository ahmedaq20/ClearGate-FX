<?php

namespace Database\Factories;

use App\Models\CapitalAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapitalAccount>
 */
class CapitalAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'balance_usd' => fake()->randomFloat(4, 0, 10000),
        ];
    }
}
