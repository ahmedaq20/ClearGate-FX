<?php

namespace App\Http\Requests\Operation;

use App\Enums\OperationCustomerDirection;
use App\Enums\OperationCustomerSettlementStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SettleCustomerOperationRequest extends ApiFormRequest
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
            'customer_direction' => ['required', Rule::in(array_column(OperationCustomerDirection::cases(), 'value'))],
            'customer_settlement_status' => ['required', Rule::in(array_column(OperationCustomerSettlementStatus::cases(), 'value'))],
            'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            'settlement_date' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
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
                if (
                    $this->input('customer_settlement_status') === OperationCustomerSettlementStatus::Completed->value
                    && ! $this->filled('box_id')
                ) {
                    $validator->errors()->add('box_id', 'حقل الصندوق مطلوب عند إكمال تسوية العميل.');
                }

                if (
                    $this->input('customer_settlement_status') === OperationCustomerSettlementStatus::Pending->value
                    && $this->filled('box_id')
                ) {
                    $validator->errors()->add('box_id', 'لا يتم اختيار صندوق عند تسجيل تسوية عميل معلقة.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'customer_direction' => 'اتجاه تسوية العميل',
            'customer_settlement_status' => 'حالة تسوية العميل',
            'settlement_date' => 'تاريخ التسوية',
            'idempotency_key' => 'مفتاح التكرار',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'box_id.exists' => 'الصندوق المحدد غير موجود.',
        ]);
    }
}
