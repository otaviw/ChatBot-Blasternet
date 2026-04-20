<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class SearchConversationMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q'                 => ['required', 'string', 'max:120'],
            'messages_per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
        ];
    }
}
