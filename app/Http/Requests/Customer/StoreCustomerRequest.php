<?php

namespace App\Http\Requests\Customer;

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
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string'],
            'category' => ['nullable', Rule::in(['regular', 'vip', 'agent', 'company'])],
            'country' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
