<?php

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;

class SetVaultBalanceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'initial_balance' => ['required', 'numeric'],
        ];
    }
}
