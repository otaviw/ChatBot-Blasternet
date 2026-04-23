<?php

namespace App\Http\Requests\Admin;

use App\Models\Reseller;
use Illuminate\Foundation\Http\FormRequest;

class StoreResellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:120'],
            'slug'          => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', 'unique:resellers,slug', 'not_in:' . implode(',', Reseller::RESERVED_SLUGS)],
            'logo'          => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique'  => 'Este slug já está em uso.',
            'slug.regex'   => 'O slug deve conter apenas letras minúsculas, números e hífens.',
            'slug.not_in'  => 'Este slug é reservado e não pode ser utilizado.',
        ];
    }
}
