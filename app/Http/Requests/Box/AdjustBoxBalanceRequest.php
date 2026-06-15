<?php

namespace App\Http\Requests\Box;

use App\Enums\BoxBalanceOperationType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class AdjustBoxBalanceRequest extends ApiFormRequest
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
            'operation_type' => ['required', Rule::enum(BoxBalanceOperationType::class)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
