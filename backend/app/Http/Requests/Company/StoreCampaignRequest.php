<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (int) $this->user()?->company_id;

        return [
            'name'          => ['required', 'string', 'max:255'],
            'type'          => ['required', Rule::in(['free', 'template', 'open'])],
            'message'       => ['nullable', 'string', 'max:4096'],
            'template_id'   => ['nullable', 'string', 'max:255'],
            'contact_ids'   => ['sometimes', 'array'],
            'contact_ids.*' => [
                'integer',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ];
    }
}
