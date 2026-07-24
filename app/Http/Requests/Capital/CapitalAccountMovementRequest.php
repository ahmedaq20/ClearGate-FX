<?php

namespace App\Http\Requests\Capital;

use App\Http\Requests\ApiFormRequest;

class CapitalAccountMovementRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:add,top_up,withdraw,withdrawal'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'transaction_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'statement' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
