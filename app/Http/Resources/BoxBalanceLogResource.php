<?php

namespace App\Http\Resources;

use App\Enums\BoxBalanceOperationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoxBalanceLogResource extends JsonResource
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
            'box_id' => $this->box_id,
            'operation_type' => $this->operation_type instanceof BoxBalanceOperationType ? $this->operation_type->value : $this->operation_type,
            'amount' => $this->amount,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator'),
            'created_at' => $this->created_at,
        ];
    }
}
