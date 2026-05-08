<?php

namespace App\Http\Requests\Transaction;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends ApiFormRequest
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
            'type' => ['required', Rule::in(['receive', 'send'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['required', 'string', 'max:10', 'exists:currencies,code'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'commission_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'commission_rate' => ['required_with:commission_type', 'nullable', 'numeric', 'gte:0'],
            'commission_sign' => ['required_with:commission_type', 'nullable', 'integer', Rule::in([1, -1])],
            'note' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string', 'max:50', 'unique:transactions,reference_number'],
            'country' => ['nullable', 'string', 'max:50'],
            'transaction_date' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'customer_id.exists' => 'العميل المحدد غير موجود',
        ]);
    }
}
