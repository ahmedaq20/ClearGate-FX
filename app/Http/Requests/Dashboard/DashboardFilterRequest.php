<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\CustomerType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class DashboardFilterRequest extends ApiFormRequest
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
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'supplier_id' => [
                'sometimes',
                'integer',
                Rule::exists('customers', 'id')->where('type', CustomerType::Supplier->value),
            ],
            'box_id' => ['sometimes', 'integer', 'exists:boxes,id'],
        ];
    }
}
