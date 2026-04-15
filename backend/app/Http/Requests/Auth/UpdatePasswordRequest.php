<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Senhas NÃO são sanitizadas — strip_tags quebraria caracteres como <, >, &
    // que são válidos em senhas. A validação de comprimento já impede abuso.

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password'         => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Informe a senha atual.',
            'password.required'         => 'Informe a nova senha.',
            'password.min'              => 'A nova senha deve ter pelo menos 8 caracteres.',
            'password.max'              => 'A nova senha não pode ultrapassar 255 caracteres.',
            'password.confirmed'        => 'A confirmacao da nova senha não confere.',
        ];
    }
}
