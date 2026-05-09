<?php

namespace App\Http\Requests\Role;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends ApiFormRequest
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
        $role = $this->route('role');
        $roleId = is_object($role) ? $role->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9_.-]+$/',
                Rule::unique('roles', 'name')->where('guard_name', 'sanctum')->ignore($roleId),
            ],
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
