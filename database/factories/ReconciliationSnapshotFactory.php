<?php

namespace Database\Factories;

use App\Models\ReconciliationSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationSnapshot>
 */
class ReconciliationSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $boxesTotal = fake()->randomFloat(4, 0, 10000);
        $freeCapital = fake()->randomFloat(4, 0, 10000);
        $capitalBalance = round($boxesTotal + $freeCapital, 4);

        return [
            'capital_balance' => $capitalBalance,
            'boxes_total_balance' => $boxesTotal,
            'free_capital' => $freeCapital,
            'difference' => 0,
            'status' => 'balanced',
            'created_by' => User::factory(),
        ];
    }
}
