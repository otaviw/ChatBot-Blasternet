<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyMetaNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'max:40'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

