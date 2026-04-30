<?php

declare(strict_types=1);


namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Remove HTML do nome antes de validar comprimento e tipo.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->cleanName($this->input('name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome.',
            'name.min'      => 'O nome deve ter pelo menos 2 caracteres.',
            'name.max'      => 'O nome não pode ultrapassar 255 caracteres.',
        ];
    }
}
