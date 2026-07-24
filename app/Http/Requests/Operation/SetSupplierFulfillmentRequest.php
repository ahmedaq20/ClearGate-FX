<?php

namespace App\Http\Requests\Operation;

use App\Enums\OperationSupplierFulfillmentStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class SetSupplierFulfillmentRequest extends ApiFormRequest
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
            'supplier_fulfillment_status' => ['required', Rule::in(array_column(OperationSupplierFulfillmentStatus::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'supplier_fulfillment_status' => 'حالة تنفيذ المورد',
        ]);
    }
}
