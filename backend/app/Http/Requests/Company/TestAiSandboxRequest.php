<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class TestAiSandboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message'     => ['required', 'string', 'max:2000'],
            'include_rag' => ['sometimes', 'boolean'],
        ];
    }
}
