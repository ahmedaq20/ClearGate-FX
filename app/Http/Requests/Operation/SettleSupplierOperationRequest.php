<?php

namespace App\Http\Requests\Operation;

use App\Http\Requests\ApiFormRequest;

class SettleSupplierOperationRequest extends ApiFormRequest
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'box_id' => ['required', 'integer', 'exists:boxes,id'],
            'operation_obligation_id' => ['nullable', 'integer', 'exists:operation_obligations,id'],
            'settlement_date' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'operation_obligation_id' => 'التزام العملية',
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
            'operation_obligation_id.exists' => 'التزام العملية المحدد غير موجود.',
        ]);
    }
}
