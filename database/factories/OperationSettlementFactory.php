<?php

namespace Database\Factories;

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationSettlementDirection;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\OperationObligation;
use App\Models\OperationSettlement;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationSettlement>
 */
class OperationSettlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_id' => Operation::factory(),
            'operation_obligation_id' => null,
            'counterparty_id' => Customer::factory(),
            'counterparty_role' => OperationCounterpartyRole::Customer->value,
            'direction' => OperationSettlementDirection::CashIn->value,
            'amount' => fake()->randomFloat(4, 100, 5000),
            'currency' => 'USD',
            'exchange_rate' => 1,
            'box_id' => null,
            'vault_id' => null,
            'settlement_date' => now()->toDateString(),
            'idempotency_key' => fake()->optional()->uuid(),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function forObligation(OperationObligation $obligation): static
    {
        return $this->state(fn (array $attributes): array => [
            'operation_id' => $obligation->operation_id,
            'operation_obligation_id' => $obligation->id,
            'counterparty_id' => $obligation->counterparty_id,
            'counterparty_role' => $obligation->counterparty_role->value,
            'amount' => $obligation->balance_amount,
            'currency' => $obligation->currency,
            'exchange_rate' => $obligation->exchange_rate,
        ]);
    }

    public function cashOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'direction' => OperationSettlementDirection::CashOut->value,
        ]);
    }

    public function fromBox(?Box $box = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'box_id' => $box?->id ?? Box::factory(),
        ]);
    }

    public function fromVault(?Vault $vault = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'vault_id' => $vault?->id ?? Vault::factory(),
        ]);
    }
}
