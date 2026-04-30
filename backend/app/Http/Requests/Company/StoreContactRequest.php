<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
        ];
    }
}
