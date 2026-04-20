<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id'    => ['nullable', 'integer', 'min:1'],
            'action'     => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['required', 'integer', 'min:1', 'max:100'],
            'page'       => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
