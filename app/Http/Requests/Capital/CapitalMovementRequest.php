<?php

namespace App\Http\Requests\Capital;

use App\Http\Requests\ApiFormRequest;

class CapitalMovementRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'transaction_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
