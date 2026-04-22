<?php

namespace App\Http\Requests\Company;

use App\Models\User;
use App\Support\UserPermissions;
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
            'name'                     => ['required', 'string', 'max:120'],
            'email'                    => ['required', 'email', 'max:190', 'unique:users,email'],
            'password'                 => ['required', 'string', 'min:8', 'max:100'],
            'role'                     => ['required', Rule::in(User::assignableRoleValuesForCompanyAdmin())],
            'is_active'                => ['sometimes', 'boolean'],
            'can_use_ai'               => ['sometimes', 'boolean'],
            'area_ids'                 => ['sometimes', 'array', 'max:50'],
            'area_ids.*'               => ['integer', 'exists:areas,id'],
            'areas'                    => ['sometimes', 'array', 'max:50'],
            'areas.*'                  => ['string', 'max:120'],
            'appointment_is_staff'     => ['sometimes', 'boolean'],
            'appointment_display_name' => ['nullable', 'string', 'max:120'],
            'permissions'              => ['sometimes', 'nullable', 'array'],
            'permissions.*'            => ['string', Rule::in(UserPermissions::ALL)],
        ];
    }
}
