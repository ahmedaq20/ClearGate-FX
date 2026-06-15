<?php

namespace App\Http\Requests\Box;

use App\Enums\BoxType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateBoxRequest extends ApiFormRequest
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
            'name' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(BoxType::class)],
            'currency' => ['nullable', 'string', 'max:10'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'max:30', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
