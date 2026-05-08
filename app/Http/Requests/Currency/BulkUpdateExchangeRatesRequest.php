<?php

namespace App\Http\Requests\Currency;

use App\Http\Requests\ApiFormRequest;

class BulkUpdateExchangeRatesRequest extends ApiFormRequest
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
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.code' => ['required', 'string', 'max:10', 'exists:currencies,code'],
            'rates.*.rate' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
