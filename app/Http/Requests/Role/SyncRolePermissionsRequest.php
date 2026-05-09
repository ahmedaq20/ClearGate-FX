<?php

namespace App\Http\Requests\Role;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class SyncRolePermissionsRequest extends ApiFormRequest
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
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string', Rule::exists('permissions', 'name')->where('guard_name', 'sanctum')],
        ];
    }
}
