<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class ManualReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text'         => ['nullable', 'string', 'max:2000'],
            'file'         => ['nullable', 'file', 'max:' . config('whatsapp.media_max_size_kb', 5120)],
            'send_outbound' => ['sometimes', 'boolean'],
        ];
    }
}
