<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class SendConversationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template_name' => ['sometimes', 'string', 'max:100'],
            'template_variables'   => ['sometimes', 'array', 'max:50'],
            'template_variables.*' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
