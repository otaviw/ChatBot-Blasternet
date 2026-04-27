<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Cast para a coluna `conversations.bot_context`.
 *
 * O bot_context armazena o estado atual do fluxo do chatbot (qual etapa,
 * quais respostas coletadas, etc.). A estrutura varia por fluxo configurado,
 * por isso o cast não restringe as chaves — apenas garante que o valor é
 * sempre um array ao ler e que valores não-array são rejeitados ao salvar.
 *
 * Estrutura: array associativo livre — ex: ['step' => 'awaiting_cpf', 'attempts' => 1]
 * null → conversa sem estado de bot ativo (equivale a bot_context = [])
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>|null>
 */
class BotContextCast implements CastsAttributes
{
    /**
     * Lê do banco. Sempre retorna array — null do banco vira [].
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            Log::warning('BotContextCast: valor inválido lido do banco.', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'key' => $key,
                'type' => gettype($decoded),
            ]);

            return [];
        }

        return $decoded;
    }

    /**
     * Salva no banco. null e [] ambos persistem como NULL (sem estado ativo).
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            Log::warning('BotContextCast: tentativa de salvar valor não-array.', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'key' => $key,
                'type' => gettype($value),
            ]);

            return null;
        }

        return json_encode($value);
    }
}
