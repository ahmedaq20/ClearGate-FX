<?php

namespace App\Http\Requests\Operation;

use App\Enums\CustomerType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOperationRequest extends ApiFormRequest
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
            'transaction_date' => ['sometimes', 'date'],
            'supplier_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('type', CustomerType::Supplier->value),
            ],
            'box_id' => ['sometimes', 'nullable', 'integer', 'exists:boxes,id'],
            'customer_id' => [
                'sometimes',
                'integer',
                Rule::exists('customers', 'id')->where('type', CustomerType::Customer->value),
            ],
            'supplier_currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'supplier_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'supplier_exchange_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'customer_currency' => ['sometimes', 'string', 'max:10'],
            'customer_amount' => ['sometimes', 'numeric', 'gt:0'],
            'customer_exchange_rate' => ['sometimes', 'numeric', 'gt:0'],
            'commission_type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'commission_rate' => ['sometimes', 'numeric', 'gte:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $operation = $this->route('operation');
                $hasSupplier = $this->has('supplier_id') ? $this->filled('supplier_id') : $operation?->supplier_id !== null;
                $hasBox = $this->has('box_id') ? $this->filled('box_id') : $operation?->box_id !== null;

                if ($hasSupplier === $hasBox) {
                    $validator->errors()->add('funding_source', 'يجب اختيار مصدر تمويل واحد فقط: مورد أو صندوق.');
                }

                if ($hasSupplier && ! $this->filledFromRequestOrOperation('supplier_currency')) {
                    $validator->errors()->add('supplier_currency', 'حقل عملة المورد مطلوب عند استخدام مورد كمصدر للأموال.');
                }

                if ($hasSupplier && ! $this->filledFromRequestOrOperation('supplier_amount')) {
                    $validator->errors()->add('supplier_amount', 'حقل مبلغ المورد مطلوب عند استخدام مورد كمصدر للأموال.');
                }

                if ($hasSupplier && ! $this->filledFromRequestOrOperation('supplier_exchange_rate')) {
                    $validator->errors()->add('supplier_exchange_rate', 'حقل سعر صرف المورد مطلوب عند استخدام مورد كمصدر للأموال.');
                }
            },
        ];
    }

    private function filledFromRequestOrOperation(string $key): bool
    {
        if ($this->has($key)) {
            return $this->filled($key);
        }

        $operation = $this->route('operation');

        return $operation !== null && filled($operation->{$key});
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
