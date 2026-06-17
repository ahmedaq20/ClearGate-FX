<?php

namespace App\Http\Requests\Capital;

use App\Http\Requests\ApiFormRequest;

class TransferCapitalToBoxRequest extends ApiFormRequest
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
            'box_id' => ['required', 'integer', 'exists:boxes,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transaction_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
