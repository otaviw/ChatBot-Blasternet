<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class TransferConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'          => ['nullable', 'string', 'in:user,area'],
            'id'            => ['nullable', 'integer', 'min:1'],
            'to_user_id'    => ['nullable', 'integer', 'min:1'],
            'to_area'       => ['nullable', 'string', 'max:120'],
            'send_outbound' => ['sometimes', 'boolean'],
        ];
    }
}
