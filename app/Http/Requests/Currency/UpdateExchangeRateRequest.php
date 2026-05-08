<?php

namespace App\Http\Requests\Currency;

use App\Http\Requests\ApiFormRequest;

class UpdateExchangeRateRequest extends ApiFormRequest
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
            'rate' => ['required', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
        ];
    }
}
