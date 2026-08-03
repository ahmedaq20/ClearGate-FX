<?php

namespace App\Http\Requests\Operation;

use App\Enums\CustomerType;
use App\Enums\OperationCommissionPayer;
use App\Enums\OperationSupplierDirection;
use App\Http\Requests\ApiFormRequest;
use App\Models\Operation;
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
            'supplier_direction' => ['sometimes', 'nullable', Rule::in(array_column(OperationSupplierDirection::cases(), 'value'))],
            'customer_currency' => ['sometimes', 'string', 'max:10'],
            'customer_amount' => ['sometimes', 'numeric', 'gt:0'],
            'customer_exchange_rate' => ['sometimes', 'numeric', 'gt:0'],
            'commission_type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'commission_rate' => ['sometimes', 'numeric', 'gte:0'],
            'commission_payer' => ['sometimes', 'nullable', Rule::in(array_column(OperationCommissionPayer::cases(), 'value'))],
            'customer_commission_amount' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'supplier_commission_amount' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
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

                if ($hasBox && $this->filled('supplier_direction')) {
                    $validator->errors()->add('supplier_direction', 'لا يتم تحديد اتجاه المورد عند استخدام صندوق كمصدر للأموال.');
                }

                $this->validateCommissionSplit($validator, $hasSupplier);
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

    private function validateCommissionSplit(Validator $validator, bool $hasSupplier): void
    {
        $commissionPayer = (string) $this->valueFromRequestOrOperation('commission_payer', OperationCommissionPayer::Customer->value);

        if (! $hasSupplier && in_array($commissionPayer, [OperationCommissionPayer::Supplier->value, OperationCommissionPayer::Both->value], true)) {
            $validator->errors()->add('commission_payer', 'لا يمكن تحميل المورد عمولة بدون اختيار مورد للعملية.');
        }

        if ($commissionPayer !== OperationCommissionPayer::Both->value) {
            return;
        }

        if (! $this->filledFromRequestOrOperation('customer_commission_amount')) {
            $validator->errors()->add('customer_commission_amount', 'حقل عمولة العميل مطلوب عند توزيع العمولة على الطرفين.');
        }

        if (! $this->filledFromRequestOrOperation('supplier_commission_amount')) {
            $validator->errors()->add('supplier_commission_amount', 'حقل عمولة المورد مطلوب عند توزيع العمولة على الطرفين.');
        }

        $customerAmount = $this->valueFromRequestOrOperation('customer_amount');
        $commissionType = $this->valueFromRequestOrOperation('commission_type');
        $commissionRate = $this->valueFromRequestOrOperation('commission_rate');
        $customerCommissionAmount = $this->valueFromRequestOrOperation('customer_commission_amount');
        $supplierCommissionAmount = $this->valueFromRequestOrOperation('supplier_commission_amount');

        if (
            ! is_numeric($customerAmount)
            || ! is_string($commissionType)
            || ! is_numeric($commissionRate)
            || ! is_numeric($customerCommissionAmount)
            || ! is_numeric($supplierCommissionAmount)
        ) {
            return;
        }

        $commissionAmount = $this->commissionAmount((float) $customerAmount, $commissionType, (float) $commissionRate);
        $splitAmount = round((float) $customerCommissionAmount + (float) $supplierCommissionAmount, 4);

        if (abs($splitAmount - $commissionAmount) > 0.00009) {
            $validator->errors()->add('commission_split', 'مجموع عمولة العميل والمورد يجب أن يساوي إجمالي العمولة.');
        }
    }

    private function valueFromRequestOrOperation(string $key, mixed $default = null): mixed
    {
        if ($this->has($key)) {
            return $this->input($key);
        }

        $operation = $this->route('operation');

        if (! $operation instanceof Operation) {
            return $default;
        }

        $value = $operation->{$key};

        if ($value instanceof OperationCommissionPayer) {
            return $value->value;
        }

        return $value ?? $default;
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
