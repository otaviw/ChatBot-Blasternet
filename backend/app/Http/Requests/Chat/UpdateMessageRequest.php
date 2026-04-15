<?php

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sanitiza o conteúdo antes de validar.
     * Normaliza o campo text → content para aceitar ambos os nomes.
     */
    protected function prepareForValidation(): void
    {
        $rawContent = $this->input('content') ?? $this->input('text');
        if ($rawContent !== null) {
            $this->merge([
                'content' => $this->cleanText((string) $rawContent),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:20000'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Informe o novo texto da mensagem para editar.',
            'content.min'      => 'O texto não pode estar vazio.',
            'content.max'      => 'O texto não pode ultrapassar 20.000 caracteres.',
        ];
    }
}
