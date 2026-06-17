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
            'type' => ['required', Rule::in([
                'daily',
                'monthly',
                'statement',
                'comparison',
                'profit-summary',
                'daily-profit',
                'monthly-profit',
                'profit-by-supplier',
                'profit-by-user',
            ])],
            'format' => ['required', Rule::in(['pdf', 'excel'])],
            'params' => ['nullable', 'array'],
        ];
    }
}
