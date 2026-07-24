<?php

namespace Database\Factories;

use App\Enums\CapitalAccountType;
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
        $balance = fake()->randomFloat(4, 0, 10000);

        return [
            'user_id' => User::factory(),
            'name' => 'Owner Capital',
            'type' => CapitalAccountType::Owner->value,
            'currency' => 'USD',
            'total_balance' => $balance,
            'unallocated_balance' => $balance,
            'allocated_balance' => 0,
            'balance_usd' => $balance,
            'free_balance_usd' => $balance,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function company(): static
    {
        return $this->state(fn (): array => [
            'name' => fake()->company(),
            'type' => CapitalAccountType::Company->value,
        ]);
    }

    public function investor(): static
    {
        return $this->state(fn (): array => [
            'name' => fake()->company(),
            'type' => CapitalAccountType::Investor->value,
        ]);
    }

    public function partner(): static
    {
        return $this->state(fn (): array => [
            'name' => fake()->name(),
            'type' => CapitalAccountType::Partner->value,
        ]);
    }
}
