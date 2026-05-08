<?php

namespace App\Http\Requests\Currency;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateCurrencyRequest extends ApiFormRequest
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
        $currency = $this->route('currency');
        $currencyId = is_object($currency) ? $currency->id : null;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:10', Rule::unique('currencies', 'code')->ignore($currencyId)],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:100'],
            'symbol' => ['sometimes', 'required', 'string', 'max:10'],
            'rate_to_usd' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
