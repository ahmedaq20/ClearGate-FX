<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationObligationResource extends JsonResource
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
            'counterparty_id' => $this->counterparty_id,
            'counterparty_role' => $this->counterparty_role?->value,
            'type' => $this->type?->value,
            'reason' => $this->reason?->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'settled_amount' => $this->settled_amount,
            'balance_amount' => $this->balance_amount,
            'status' => $this->status?->value,
            'created_by' => $this->created_by,
            'operation' => $this->whenLoaded('operation'),
            'counterparty' => $this->whenLoaded('counterparty'),
            'settlements' => OperationSettlementResource::collection($this->whenLoaded('settlements')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
