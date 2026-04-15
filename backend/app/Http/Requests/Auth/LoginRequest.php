<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sanitiza o e-mail antes da validação.
     * Senha não é sanitizada para não corromper caracteres especiais.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->cleanEmail($this->input('email')),
        ]);
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'Informe o e-mail.',
            'email.email'       => 'E-mail inválido.',
            'email.max'         => 'E-mail muito longo.',
            'password.required' => 'Informe a senha.',
        ];
    }
}
