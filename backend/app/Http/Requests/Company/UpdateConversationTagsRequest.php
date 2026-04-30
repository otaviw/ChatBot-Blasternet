<?php

declare(strict_types=1);


namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tags'   => ['present', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
