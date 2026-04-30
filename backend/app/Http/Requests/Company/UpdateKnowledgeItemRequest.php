<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKnowledgeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title'     => ['required', 'string', 'max:190'],
            'content'   => ['required', 'string', 'max:20000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
