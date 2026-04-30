<?php

declare(strict_types=1);


namespace App\Services\Ai\Providers;

class NullAiProvider implements AiProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array{
     *     ok:bool,
     *     text:?string,
     *     error:mixed,
     *     meta:array<string, mixed>|null
     * }
     */
    public function reply(array $messages, array $options = []): array
    {
        unset($messages, $options);

        return [
            'ok' => false,
            'text' => null,
            'error' => 'ai_provider_unavailable',
            'meta' => [
                'provider' => 'null',
                'message' => (string) config('ai.providers.null.fallback_message', 'Servico de IA indisponivel no momento.'),
            ],
        ];
    }
}
