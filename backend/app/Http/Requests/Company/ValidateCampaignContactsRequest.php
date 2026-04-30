<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidateCampaignContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'          => ['required', Rule::in(['free', 'template', 'open'])],
            'contact_ids'   => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer'],
        ];
    }
}
