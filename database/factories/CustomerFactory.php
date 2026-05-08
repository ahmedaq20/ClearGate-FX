<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
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

        return [
            'user_id' => $user->id,
            'vault_id' => $vault->id,
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'note' => fake()->sentence(),
            'category' => 'regular',
            'balance_usd' => 0,
            'country' => fake()->country(),
            'is_active' => true,
        ];
    }
}
