<?php

namespace App\Http\Requests\Report;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ExportReportRequest extends ApiFormRequest
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
            'type' => ['required', Rule::in(['daily', 'monthly', 'statement', 'comparison'])],
            'format' => ['required', Rule::in(['pdf', 'excel'])],
            'params' => ['nullable', 'array'],
        ];
    }
}
