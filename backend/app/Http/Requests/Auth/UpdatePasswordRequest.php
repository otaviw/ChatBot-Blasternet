<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Security\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => [...PasswordRules::required(), 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Informe a senha atual.',
            'password.required' => 'Informe a nova senha.',
            'password.min' => 'A nova senha deve ter pelo menos 8 caracteres.',
            'password.numbers' => 'A nova senha deve incluir pelo menos 1 numero.',
            'password.max' => 'A nova senha nao pode ultrapassar 255 caracteres.',
            'password.confirmed' => 'A confirmacao da nova senha nao confere.',
        ];
    }
}
