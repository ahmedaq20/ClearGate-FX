<?php

namespace App\Http\Requests\Customer;

use App\Enums\CustomerType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends ApiFormRequest
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
            'customer_code' => ['required', 'string', 'max:20', Rule::unique('customers', 'customer_code')],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'type' => ['required', Rule::enum(CustomerType::class)],
            'note' => ['nullable', 'string'],
            'category' => ['nullable', Rule::in(['regular', 'vip', 'agent', 'company'])],
            'country' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'balance_usd' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
