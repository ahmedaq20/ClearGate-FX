<?php

namespace Database\Factories;

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationReason;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationObligation>
 */
class OperationObligationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(4, 100, 5000);

        return [
            'operation_id' => Operation::factory(),
            'counterparty_id' => Customer::factory(),
            'counterparty_role' => OperationCounterpartyRole::Customer->value,
            'type' => OperationObligationType::Receivable->value,
            'reason' => OperationObligationReason::CustomerPrincipal->value,
            'amount' => $amount,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'settled_amount' => 0,
            'balance_amount' => $amount,
            'status' => OperationObligationStatus::Open->value,
            'created_by' => User::factory(),
        ];
    }

    public function payable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'counterparty_id' => Customer::factory()->state(['type' => 'supplier']),
            'counterparty_role' => OperationCounterpartyRole::Supplier->value,
            'type' => OperationObligationType::Payable->value,
            'reason' => OperationObligationReason::SupplierSettlement->value,
        ]);
    }

    public function partiallySettled(): static
    {
        return $this->state(function (array $attributes): array {
            $amount = (float) ($attributes['amount'] ?? fake()->randomFloat(4, 100, 5000));
            $settledAmount = round($amount / 2, 4);

            return [
                'amount' => $amount,
                'settled_amount' => $settledAmount,
                'balance_amount' => round($amount - $settledAmount, 4),
                'status' => OperationObligationStatus::PartiallySettled->value,
            ];
        });
    }

    public function settled(): static
    {
        return $this->state(function (array $attributes): array {
            $amount = (float) ($attributes['amount'] ?? fake()->randomFloat(4, 100, 5000));

            return [
                'amount' => $amount,
                'settled_amount' => $amount,
                'balance_amount' => 0,
                'status' => OperationObligationStatus::Settled->value,
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OperationObligationStatus::Cancelled->value,
        ]);
    }
}
