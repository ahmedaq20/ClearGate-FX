<?php

namespace Database\Factories;

use App\Models\Box;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operation>
 */
class OperationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(4, 100, 5000);
        $commissionRate = fake()->randomFloat(4, 0, 5);
        $commissionAmount = round($amount * ($commissionRate / 100), 4);

        return [
            'reference_number' => 'TRX-'.now()->year.'-'.fake()->unique()->numerify('#####'),
            'transaction_date' => now()->toDateString(),
            'supplier_id' => Customer::factory()->create(['type' => 'supplier'])->id,
            'box_id' => null,
            'customer_id' => Customer::factory()->create(['type' => 'customer'])->id,
            'supplier_currency' => 'USD',
            'supplier_amount' => $amount,
            'supplier_exchange_rate' => 1,
            'customer_currency' => 'USD',
            'customer_amount' => $amount,
            'customer_exchange_rate' => 1,
            'commission_type' => 'percentage',
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionAmount,
            'customer_net_amount' => round($amount - $commissionAmount, 4),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function boxFunded(?Box $box = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'supplier_id' => null,
            'box_id' => $box?->id ?? Box::factory(),
        ]);
    }
}
