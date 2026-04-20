<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class StoreAiFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'helpful' => ['required', 'boolean'],
            'reason'  => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
