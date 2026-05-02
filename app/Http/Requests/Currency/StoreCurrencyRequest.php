<?php

namespace App\Http\Requests\Currency;

use App\Http\Requests\ApiFormRequest;

class StoreCurrencyRequest extends ApiFormRequest
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
            'code' => ['required', 'string', 'max:10', 'unique:currencies,code'],
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:10'],
            'rate_to_usd' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
