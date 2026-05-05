<?php

declare(strict_types=1);

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMyCompanyIxcRequest extends FormRequest
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
            'ixc_base_url' => ['nullable', 'url', 'max:500'],
            'ixc_api_token' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'ixc_self_signed' => ['sometimes', 'boolean'],
            'ixc_timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:60'],
            'ixc_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
