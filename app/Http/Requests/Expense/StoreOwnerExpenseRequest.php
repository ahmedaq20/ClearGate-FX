<?php

namespace App\Http\Requests\Expense;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreOwnerExpenseRequest extends ApiFormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['vehicle', 'housing', 'family', 'education', 'medical', 'travel', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'expense_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
