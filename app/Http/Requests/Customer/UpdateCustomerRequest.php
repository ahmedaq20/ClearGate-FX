<?php

namespace App\Http\Requests\Customer;

use App\Enums\CustomerType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends ApiFormRequest
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
            'customer_code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('customers', 'customer_code')->ignore($this->route('customer'))],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'type' => ['sometimes', 'required', Rule::enum(CustomerType::class)],
            'note' => ['nullable', 'string'],
            'category' => ['nullable', Rule::in(['regular', 'vip', 'agent', 'company'])],
            'country' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'balance_usd' => ['nullable', 'numeric', 'min:0'],

        ];
    }
}
