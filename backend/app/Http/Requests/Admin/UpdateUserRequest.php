<?php

declare(strict_types=1);


namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\Security\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
            'email'      => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'role'       => ['required', Rule::in(User::assignableRoleValuesForSystemAdmin())],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'reseller_id' => ['nullable', 'integer', 'exists:resellers,id'],
            'is_active'  => ['required', 'boolean'],
            'can_use_ai' => ['sometimes', 'boolean'],
            'password'   => PasswordRules::optional(100),
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
