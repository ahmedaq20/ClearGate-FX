<?php

namespace App\Http\Requests\Role;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends ApiFormRequest
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
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9_.-]+$/',
                Rule::unique('roles', 'name')->where('guard_name', 'sanctum'),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['required', 'string', Rule::exists('permissions', 'name')->where('guard_name', 'sanctum')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.regex' => 'يجب أن يكون اسم الدور مفتاحاً إنجليزياً صالحاً.',
        ]);
    }
}
