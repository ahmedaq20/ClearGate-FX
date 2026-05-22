<?php

namespace App\Http\Requests\Transaction;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
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
            'type' => ['required', Rule::in(['receive', 'send', 'transfer'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['required', 'string', 'max:10', 'exists:currencies,code'],
            'exchange_rate' => ['required_if:type,transfer', 'nullable', 'numeric', 'gt:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'from_customer_id' => ['required_if:type,transfer', 'nullable', 'integer', 'exists:customers,id'],
            'to_customer_id' => ['required_if:type,transfer', 'nullable', 'integer', 'exists:customers,id'],
            'commission_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'commission_rate' => ['required_with:commission_type', 'nullable', 'numeric', 'gte:0'],
            'commission_sign' => ['required_with:commission_type', 'nullable', 'integer', Rule::in([1, -1])],
            'note' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string', 'max:50', 'unique:transactions,reference_number'],
            'country' => ['nullable', 'string', 'max:50'],
            'transaction_date' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v): void {
            if ($this->input('type') === 'transfer'
                && $this->input('from_customer_id') !== null
                && (string) $this->input('from_customer_id') === (string) $this->input('to_customer_id')
            ) {
                $v->errors()->add('to_customer_id', 'لا يمكن التحويل إلى نفس العميل');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'customer_id.exists' => 'العميل المحدد غير موجود',
            'from_customer_id.exists' => 'العميل المُحوَّل منه غير موجود',
            'to_customer_id.exists' => 'العميل المُحوَّل إليه غير موجود',
        ]);
    }
}
