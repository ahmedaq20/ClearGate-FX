<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationSettlementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation_id' => $this->operation_id,
            'operation_obligation_id' => $this->operation_obligation_id,
            'counterparty_id' => $this->counterparty_id,
            'counterparty_role' => $this->counterparty_role?->value,
            'direction' => $this->direction?->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'box_id' => $this->box_id,
            'vault_id' => $this->vault_id,
            'settlement_date' => $this->settlement_date?->toDateString(),
            'idempotency_key' => $this->idempotency_key,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'operation' => $this->whenLoaded('operation'),
            'obligation' => OperationObligationResource::make($this->whenLoaded('obligation')),
            'counterparty' => $this->whenLoaded('counterparty'),
            'box' => $this->whenLoaded('box'),
            'vault' => $this->whenLoaded('vault'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
