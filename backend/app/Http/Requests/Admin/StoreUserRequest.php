<?php

declare(strict_types=1);


namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\Security\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:120'],
            'email'      => ['required', 'email', 'max:190', 'unique:users,email'],
            'password'   => PasswordRules::required(100),
            'role'       => ['required', Rule::in(User::assignableRoleValuesForSystemAdmin())],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'reseller_id' => ['nullable', 'integer', 'exists:resellers,id'],
            'is_active'  => ['sometimes', 'boolean'],
            'can_use_ai' => ['sometimes', 'boolean'],
            'area_ids'   => ['sometimes', 'array', 'max:50'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
            'areas'      => ['sometimes', 'array', 'max:50'],
            'areas.*'    => ['string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.numbers' => 'A senha deve incluir pelo menos 1 número.',
        ];
    }
}
