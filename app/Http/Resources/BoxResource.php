<?php

namespace App\Http\Resources;

use App\Enums\BoxType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoxResource extends JsonResource
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
            'name' => $this->name,
            'type' => $this->type instanceof BoxType ? $this->type->value : $this->type,
            'current_balance' => $this->current_balance,
            'currency' => $this->currency,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user' => $this->whenLoaded('assignedUser'),
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
