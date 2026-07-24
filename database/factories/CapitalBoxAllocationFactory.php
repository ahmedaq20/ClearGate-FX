<?php

namespace Database\Factories;

use App\Models\Box;
use App\Models\CapitalAccount;
use App\Models\CapitalBoxAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapitalBoxAllocation>
 */
class CapitalBoxAllocationFactory extends Factory
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
            'box_id' => Box::factory(),
            'currency' => 'USD',
            'allocated_balance' => fake()->randomFloat(4, 0, 10000),
        ];
    }
}
