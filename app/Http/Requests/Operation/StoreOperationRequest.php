<?php

namespace App\Http\Requests\Operation;

use App\Enums\CustomerType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOperationRequest extends ApiFormRequest
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
            'transaction_date' => ['required', 'date'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('type', CustomerType::Supplier->value),
            ],
            'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('type', CustomerType::Customer->value),
            ],
            'supplier_currency' => ['required', 'string', 'max:10'],
            'supplier_amount' => ['required', 'numeric', 'gt:0'],
            'supplier_exchange_rate' => ['required', 'numeric', 'gt:0'],
            'customer_currency' => ['required', 'string', 'max:10'],
            'customer_amount' => ['required', 'numeric', 'gt:0'],
            'customer_exchange_rate' => ['required', 'numeric', 'gt:0'],
            'commission_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'commission_rate' => ['required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hasSupplier = $this->filled('supplier_id');
                $hasBox = $this->filled('box_id');

                if ($hasSupplier === $hasBox) {
                    $validator->errors()->add('funding_source', 'يجب اختيار مصدر تمويل واحد فقط: مورد أو صندوق.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'supplier_id.exists' => 'المورد المحدد غير موجود.',
            'customer_id.exists' => 'العميل المحدد غير موجود.',
            'box_id.exists' => 'الصندوق المحدد غير موجود.',
        ]);
    }
}
