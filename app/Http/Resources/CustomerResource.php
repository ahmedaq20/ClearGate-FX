<?php

namespace App\Http\Resources;

use App\Enums\CustomerType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
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
            'customer_code' => $this->customer_code,
            'name' => $this->name,
            'phone' => $this->phone,
            'type' => $this->type instanceof CustomerType ? $this->type->value : $this->type,
            'note' => $this->note,
            'category' => $this->category,
            'balance_usd' => $this->balance_usd,
            'country' => $this->country,
            'is_active' => $this->is_active,
            'user' => $this->whenLoaded('user'),
            'vault' => $this->whenLoaded('vault'),
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
