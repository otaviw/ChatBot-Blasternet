<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendInternalAiConversationMessageRequest extends FormRequest
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
            'content' => ['nullable', 'string', 'max:8000', 'required_without:text'],
            'text' => ['nullable', 'string', 'max:8000', 'required_without:content'],
        ];
    }
}
