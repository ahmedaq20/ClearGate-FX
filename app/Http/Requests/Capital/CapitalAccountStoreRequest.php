<?php

namespace App\Http\Requests\Capital;

use App\Http\Requests\ApiFormRequest;

class CapitalAccountStoreRequest extends ApiFormRequest
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
            'type' => ['required', 'string', 'in:own,owner,investor'],
            'name' => ['nullable', 'required_if:type,investor', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'max:10'],
            'transaction_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'statement' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
