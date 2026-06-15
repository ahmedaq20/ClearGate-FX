<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationResource extends JsonResource
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
            'reference_number' => $this->reference_number,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'supplier_id' => $this->supplier_id,
            'box_id' => $this->box_id,
            'customer_id' => $this->customer_id,
            'supplier_currency' => $this->supplier_currency,
            'supplier_amount' => $this->supplier_amount,
            'supplier_exchange_rate' => $this->supplier_exchange_rate,
            'customer_currency' => $this->customer_currency,
            'customer_amount' => $this->customer_amount,
            'customer_exchange_rate' => $this->customer_exchange_rate,
            'commission_type' => $this->commission_type,
            'commission_rate' => $this->commission_rate,
            'commission_amount' => $this->commission_amount,
            'customer_net_amount' => $this->customer_net_amount,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'customer' => $this->whenLoaded('customer'),
            'supplier' => $this->whenLoaded('supplier'),
            'box' => $this->whenLoaded('box'),
            'creator' => $this->whenLoaded('creator'),
            'box_balance_logs' => $this->whenLoaded('boxBalanceLogs'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
