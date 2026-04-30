<?php

declare(strict_types=1);


namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSupportTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'string', 'max:30'],
            'status'     => ['nullable', Rule::in(['open', 'closed'])],
        ];
    }
}
