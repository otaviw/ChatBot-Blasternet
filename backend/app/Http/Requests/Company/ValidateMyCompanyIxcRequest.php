<?php

declare(strict_types=1);

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class ValidateMyCompanyIxcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_url' => ['nullable', 'url', 'max:500'],
            'api_token' => ['nullable', 'string', 'max:4000'],
            'self_signed' => ['sometimes', 'boolean'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:60'],
        ];
    }
}
