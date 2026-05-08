<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vault>
 */
class VaultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $initialBalance = fake()->randomFloat(4, 100, 1000);

        return [
            'user_id' => User::factory(),
            'name' => fake()->name().' Vault',
            'initial_balance' => $initialBalance,
            'balance_usd' => $initialBalance,
            'currency_code' => 'USD',
            'is_active' => true,
        ];
    }
}
