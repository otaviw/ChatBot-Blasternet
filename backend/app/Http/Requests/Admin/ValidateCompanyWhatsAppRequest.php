<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ValidateCompanyWhatsAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone_number_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'access_token'    => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
