<?php

namespace App\Http\Requests\Box;

use App\Enums\BoxType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreBoxRequest extends ApiFormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(BoxType::class)],
            'current_balance' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'max:30', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
