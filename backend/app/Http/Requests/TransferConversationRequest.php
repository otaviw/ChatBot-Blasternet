<?php

declare(strict_types=1);


namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferConversationRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(['user', 'area'])],
            'id' => ['required', 'integer', 'min:1'],
            'send_outbound' => ['sometimes', 'boolean'],
        ];
    }
}

