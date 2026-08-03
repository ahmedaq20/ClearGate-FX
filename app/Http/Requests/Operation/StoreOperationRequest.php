<?php

namespace App\Http\Requests\Operation;

use App\Enums\CustomerType;
use App\Enums\OperationCommissionPayer;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierDirection;
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
            'supplier_direction' => ['nullable', Rule::in(array_column(OperationSupplierDirection::cases(), 'value'))],
            'customer_currency' => ['required', 'string', 'max:10'],
            'customer_amount' => ['required', 'numeric', 'gt:0'],
            'customer_exchange_rate' => ['required', 'numeric', 'gt:0'],
            'commission_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'commission_rate' => ['required', 'numeric', 'gte:0'],
            'commission_payer' => ['nullable', Rule::in(array_column(OperationCommissionPayer::cases(), 'value'))],
            'customer_commission_amount' => ['nullable', 'numeric', 'gte:0'],
            'supplier_commission_amount' => ['nullable', 'numeric', 'gte:0'],
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
            'supplier_direction' => [
                'description' => 'Supplier money direction. supplier_pays_intermediary means supplier cash comes in and the customer receives from intermediary.',
                'example' => OperationSupplierDirection::SupplierPaysIntermediary->value,
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
            'commission_payer' => [
                'description' => 'Who pays the commission. Allowed values: customer, supplier, both. Defaults to customer.',
                'example' => OperationCommissionPayer::Customer->value,
            ],
            'customer_commission_amount' => [
                'description' => 'Customer-paid part of the commission. Required when commission_payer is both.',
                'example' => 10,
            ],
            'supplier_commission_amount' => [
                'description' => 'Supplier-paid part of the commission. Required when commission_payer is both.',
                'example' => 10,
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

                if ($hasBox && $this->filled('supplier_direction')) {
                    $validator->errors()->add('supplier_direction', 'لا يتم تحديد اتجاه المورد عند استخدام صندوق كمصدر للأموال.');
                }

                if ($hasBox && $this->input('status') === OperationStatus::Pending->value) {
                    $validator->errors()->add('status', 'لا يمكن إنشاء عملية معلقة عند استخدام صندوق كمصدر للأموال');
                }

                $this->validateCommissionSplit($validator, $hasSupplier);
            },
        ];
    }

    private function validateCommissionSplit(Validator $validator, bool $hasSupplier): void
    {
        $commissionPayer = (string) $this->input('commission_payer', OperationCommissionPayer::Customer->value);

        if (! $hasSupplier && in_array($commissionPayer, [OperationCommissionPayer::Supplier->value, OperationCommissionPayer::Both->value], true)) {
            $validator->errors()->add('commission_payer', 'لا يمكن تحميل المورد عمولة بدون اختيار مورد للعملية.');
        }

        if ($commissionPayer !== OperationCommissionPayer::Both->value) {
            return;
        }

        if (! $this->filled('customer_commission_amount')) {
            $validator->errors()->add('customer_commission_amount', 'حقل عمولة العميل مطلوب عند توزيع العمولة على الطرفين.');
        }

        if (! $this->filled('supplier_commission_amount')) {
            $validator->errors()->add('supplier_commission_amount', 'حقل عمولة المورد مطلوب عند توزيع العمولة على الطرفين.');
        }

        if (! is_numeric($this->input('customer_amount')) || ! is_numeric($this->input('commission_rate'))) {
            return;
        }

        if (! is_numeric($this->input('customer_commission_amount')) || ! is_numeric($this->input('supplier_commission_amount'))) {
            return;
        }

        $commissionAmount = $this->commissionAmount(
            (float) $this->input('customer_amount'),
            (string) $this->input('commission_type'),
            (float) $this->input('commission_rate')
        );
        $splitAmount = round((float) $this->input('customer_commission_amount') + (float) $this->input('supplier_commission_amount'), 4);

        if (abs($splitAmount - $commissionAmount) > 0.00009) {
            $validator->errors()->add('commission_split', 'مجموع عمولة العميل والمورد يجب أن يساوي إجمالي العمولة.');
        }
    }

    private function commissionAmount(float $customerAmount, string $commissionType, float $commissionRate): float
    {
        return match ($commissionType) {
            'percentage' => round($customerAmount * ($commissionRate / 100), 4),
            'fixed' => round($commissionRate, 4),
            default => 0.0,
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'supplier_id.exists' => 'المورد المحدد غير موجود.',
            'supplier_direction.in' => 'اتجاه المورد غير صالح.',
            'customer_id.exists' => 'العميل المحدد غير موجود.',
            'box_id.exists' => 'الصندوق المحدد غير موجود.',
        ]);
    }
}
