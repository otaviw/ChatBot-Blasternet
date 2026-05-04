<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class CreateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_name'  => ['nullable', 'string', 'max:160'],
            'send_template'  => ['sometimes', 'boolean'],
            'template_name'  => ['sometimes', 'string', 'max:100'],
            'template_variables'   => ['sometimes', 'array', 'max:50'],
            'template_variables.*' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
