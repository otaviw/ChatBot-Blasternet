<?php

declare(strict_types=1);


namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Cast para a coluna `company_bot_settings.ai_chatbot_rules`.
 *
 * Cada regra é um objeto (array associativo) que instrui o comportamento do
 * chatbot de IA — ex: tópicos proibidos, respostas obrigatórias, limites, etc.
 * O cast garante que o array armazenado contenha apenas objetos, descartando
 * items inválidos (strings, números, null).
 *
 * Estrutura esperada: lista de objetos — ex:
 *   [
 *     ['type' => 'forbidden_topic', 'value' => 'concorrência'],
 *     ['type' => 'required_greeting', 'response' => 'Olá!'],
 *   ]
 * null → nenhuma regra configurada
 *
 * @implements CastsAttributes<list<array<string, mixed>>|null, list<array<string, mixed>>|null>
 */
class AiChatbotRulesCast implements CastsAttributes
{
    /**
     * Lê do banco. Filtra itens que não são arrays (objetos).
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            Log::warning('AiChatbotRulesCast: valor inválido lido do banco.', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'key' => $key,
                'type' => gettype($decoded),
            ]);

            return null;
        }

        return array_values(array_filter($decoded, fn ($item) => is_array($item)));
    }

    /**
     * Salva no banco. Descarta itens que não são arrays; null persiste como NULL.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            Log::warning('AiChatbotRulesCast: tentativa de salvar valor não-array.', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'key' => $key,
                'type' => gettype($value),
            ]);

            return null;
        }

        $rules = array_values(array_filter($value, fn ($item) => is_array($item)));

        return json_encode($rules);
    }
}
