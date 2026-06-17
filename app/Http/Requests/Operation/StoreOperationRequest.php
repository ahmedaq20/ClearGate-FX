<?php

namespace App\Http\Requests\Operation;

use App\Enums\CustomerType;
use App\Enums\OperationStatus;
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
            'status' => ['nullable', Rule::in([OperationStatus::Pending->value, OperationStatus::Completed->value])],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('type', CustomerType::Customer->value),
            ],
            'supplier_currency' => ['nullable', 'string', 'max:10'],
            'supplier_amount' => ['nullable', 'numeric', 'gt:0'],
            'supplier_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'customer_currency' => ['required', 'string', 'max:10'],
            'customer_amount' => ['required', 'numeric', 'gt:0'],
            'customer_exchange_rate' => ['required', 'numeric', 'gt:0'],
            'commission_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'commission_rate' => ['required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'transaction_date' => [
                'description' => 'Operation transaction date.',
                'example' => '2026-06-15',
            ],
            'supplier_id' => [
                'description' => 'Supplier ID for supplier-funded operations. Leave null when using a box.',
                'example' => 5,
            ],
            'box_id' => [
                'description' => 'Box ID for box-funded operations. Leave null when using a supplier.',
                'example' => null,
            ],
            'status' => [
                'description' => 'Required for supplier-funded operations. Allowed values: pending, completed. Box-funded operations are stored as completed automatically.',
                'example' => OperationStatus::Pending->value,
            ],
            'customer_id' => [
                'description' => 'Receiving customer ID.',
                'example' => 10,
            ],
            'supplier_currency' => [
                'description' => 'Supplier-side currency code.',
                'example' => 'USD',
            ],
            'supplier_amount' => [
                'description' => 'Supplier-side amount.',
                'example' => 1000,
            ],
            'supplier_exchange_rate' => [
                'description' => 'Supplier-side exchange rate.',
                'example' => 1,
            ],
            'customer_currency' => [
                'description' => 'Customer-side currency code.',
                'example' => 'USD',
            ],
            'customer_amount' => [
                'description' => 'Amount paid to the customer.',
                'example' => 1000,
            ],
            'customer_exchange_rate' => [
                'description' => 'Customer-side exchange rate.',
                'example' => 1,
            ],
            'commission_type' => [
                'description' => 'Commission type.',
                'example' => 'percentage',
            ],
            'commission_rate' => [
                'description' => 'Commission rate or fixed value.',
                'example' => 2,
            ],
            'notes' => [
                'description' => 'Optional operation notes.',
                'example' => 'Supplier funded transfer.',
            ],
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

                if ($hasSupplier && ! $this->filled('status')) {
                    $validator->errors()->add('status', 'حقل الحالة مطلوب.');
                }

                if ($hasSupplier && ! $this->filled('supplier_currency')) {
                    $validator->errors()->add('supplier_currency', 'حقل عملة المورد مطلوب عند استخدام مورد كمصدر للأموال.');
                }

                if ($hasSupplier && ! $this->filled('supplier_amount')) {
                    $validator->errors()->add('supplier_amount', 'حقل مبلغ المورد مطلوب عند استخدام مورد كمصدر للأموال.');
                }

                if ($hasSupplier && ! $this->filled('supplier_exchange_rate')) {
                    $validator->errors()->add('supplier_exchange_rate', 'حقل سعر صرف المورد مطلوب عند استخدام مورد كمصدر للأموال.');
                }

                if ($hasBox && $this->input('status') === OperationStatus::Pending->value) {
                    $validator->errors()->add('status', 'لا يمكن إنشاء عملية معلقة عند استخدام صندوق كمصدر للأموال');
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
