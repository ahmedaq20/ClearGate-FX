<?php

namespace App\Http\Requests\Operation;

use App\Http\Requests\ApiFormRequest;

class CancelOperationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'cancellation_reason' => [
                'description' => 'Reason for cancelling the operation.',
                'example' => 'Supplier did not settle externally.',
            ],
        ];
    }
}
