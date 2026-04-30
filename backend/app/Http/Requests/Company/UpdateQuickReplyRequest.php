<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuickReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'text'  => ['required', 'string', 'max:2000'],
        ];
    }
}
