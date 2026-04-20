<?php

namespace App\Http\Requests\Company;

use App\Models\User;
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
            'name'                     => ['required', 'string', 'max:120'],
            'email'                    => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'role'                     => ['required', Rule::in(User::assignableRoleValuesForCompanyAdmin())],
            'is_active'                => ['required', 'boolean'],
            'can_use_ai'               => ['sometimes', 'boolean'],
            'password'                 => ['nullable', 'string', 'min:8', 'max:100'],
            'area_ids'                 => ['sometimes', 'array', 'max:50'],
            'area_ids.*'               => ['integer', 'exists:areas,id'],
            'areas'                    => ['sometimes', 'array', 'max:50'],
            'areas.*'                  => ['string', 'max:120'],
            'appointment_is_staff'     => ['sometimes', 'boolean'],
            'appointment_display_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
