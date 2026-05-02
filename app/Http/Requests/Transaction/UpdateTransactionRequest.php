<?php

namespace App\Http\Requests\Transaction;

use App\Http\Requests\ApiFormRequest;

class UpdateTransactionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:50'],
            'transaction_date' => ['nullable', 'date'],
        ];
    }
}
