<?php

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;

class CreateConversationRequest extends FormRequest
{
    use SanitizesInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sanitiza conteúdo, nome do grupo e normaliza o tipo antes de validar.
     */
    protected function prepareForValidation(): void
    {
        $rawContent = $this->input('content') ?? $this->input('text');
        if ($rawContent !== null) {
            $this->merge([
                'content' => $this->cleanText((string) $rawContent),
            ]);
        }

        $rawName = $this->input('name') ?? $this->input('group_name');
        if ($rawName !== null) {
            $this->merge([
                'name' => $this->cleanName((string) $rawName),
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
        $type = mb_strtolower(trim((string) ($this->input('type') ?? 'direct')));

        if ($type === 'group') {
            return [
                'type'              => ['required', 'string', 'in:direct,group'],
                'name'              => ['nullable', 'string', 'max:120'],
                'content'           => ['nullable', 'string', 'max:20000'],
                'participant_ids'   => ['required', 'array', 'min:2'],
                'participant_ids.*' => ['integer', 'min:1'],
            ];
        }

        return [
            'type'         => ['nullable', 'string', 'in:direct,group'],
            'recipient_id' => ['required', 'integer', 'min:1'],
            'content'      => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_id.required'   => 'recipient_id e obrigatório.',
            'recipient_id.integer'    => 'recipient_id inválido.',
            'recipient_id.min'        => 'recipient_id inválido.',
            'participant_ids.required' => 'Selecione os participantes do grupo.',
            'participant_ids.min'     => 'Selecione pelo menos 2 participantes.',
            'participant_ids.*.integer' => 'ID de participante inválido.',
            'name.max'                => 'O nome do grupo não pode ultrapassar 120 caracteres.',
            'content.max'             => 'O texto não pode ultrapassar 20.000 caracteres.',
        ];
    }
}
