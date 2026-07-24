<?php

namespace App\Http\Resources;

use App\Enums\OperationStatus;
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
            'customer_direction' => $this->customer_direction?->value,
            'customer_settlement_status' => $this->customer_settlement_status?->value,
            'customer_settled_at' => $this->customer_settled_at,
            'supplier_fulfillment_status' => $this->supplier_fulfillment_status?->value,
            'supplier_fulfilled_at' => $this->supplier_fulfilled_at,
            'supplier_settlement_status' => $this->supplier_settlement_status?->value,
            'supplier_settled_at' => $this->supplier_settled_at,
            'commission_currency' => $this->commission_currency,
            'status' => $this->status instanceof OperationStatus ? $this->status->value : $this->status,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'customer' => $this->whenLoaded('customer'),
            'supplier' => $this->whenLoaded('supplier'),
            'box' => $this->whenLoaded('box'),
            'creator' => $this->whenLoaded('creator'),
            'box_balance_logs' => $this->whenLoaded('boxBalanceLogs'),
            'obligations' => OperationObligationResource::collection($this->whenLoaded('obligations')),
            'settlements' => OperationSettlementResource::collection($this->whenLoaded('settlements')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
