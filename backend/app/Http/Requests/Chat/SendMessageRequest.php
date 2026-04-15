<?php

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sanitiza o conteúdo da mensagem e normaliza o tipo antes de validar.
     */
    protected function prepareForValidation(): void
    {
        $rawContent = $this->input('content') ?? $this->input('text');
        if ($rawContent !== null) {
            $this->merge([
                'content' => $this->cleanText((string) $rawContent),
            ]);
        }

        $type = $this->input('type');
        if ($type !== null) {
            $this->merge([
                'type' => mb_strtolower(trim((string) $type)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Texto OU arquivo é obrigatório
            'content' => ['nullable', 'string', 'max:20000', 'required_without:file'],
            'type'    => ['nullable', 'string', 'in:text,image,file'],
            'file'    => [
                'nullable',
                'file',
                'max:10240', // 10 MB
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,zip,mp4,mp3,ogg,wav',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required_without' => 'Envie texto ou anexo para continuar.',
            'content.max'              => 'O texto nao pode ultrapassar 20.000 caracteres.',
            'type.in'                  => 'Tipo de mensagem invalido. Use: text, image ou file.',
            'file.max'                 => 'O arquivo nao pode ser maior que 10 MB.',
            'file.mimes'               => 'Tipo de arquivo nao permitido.',
        ];
    }
}
