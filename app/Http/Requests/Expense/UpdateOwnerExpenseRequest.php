<?php

namespace App\Http\Requests\Expense;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateOwnerExpenseRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', Rule::in(['vehicle', 'housing', 'family', 'education', 'medical', 'travel', 'other'])],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'expense_date' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
