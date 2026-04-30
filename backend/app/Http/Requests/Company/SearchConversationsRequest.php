<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use App\Support\ConversationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q'           => ['nullable', 'string', 'max:120'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim'    => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'status'      => ['nullable', 'string', Rule::in(ConversationStatus::all())],
        ];
    }
}
