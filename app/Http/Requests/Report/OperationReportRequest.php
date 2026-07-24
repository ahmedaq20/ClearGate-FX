<?php

namespace App\Http\Requests\Report;

use App\Enums\OperationCounterpartyRole;
use App\Enums\OperationObligationStatus;
use App\Enums\OperationObligationType;
use App\Enums\OperationStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class OperationReportRequest extends ApiFormRequest
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
            'date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'supplier_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'box_id' => ['sometimes', 'integer', 'exists:boxes,id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'status' => ['sometimes', Rule::enum(OperationStatus::class)],
            'obligation_type' => ['sometimes', Rule::enum(OperationObligationType::class)],
            'counterparty_role' => ['sometimes', Rule::enum(OperationCounterpartyRole::class)],
            'obligation_status' => ['sometimes', Rule::enum(OperationObligationStatus::class)],
            'currency' => ['sometimes', 'string', 'max:10'],
            'period' => ['sometimes', Rule::in(['daily', 'monthly', 'yearly', 'custom'])],
            'group_by_status' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
