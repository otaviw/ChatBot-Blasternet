<?php

namespace Tests\Fakes\Ai;

use App\Services\Ai\Providers\AiProvider;

class UnknownToolTestAiProvider implements AiProvider
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

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
        unset($messages);

        self::$calls++;

        if (self::$calls === 1) {
            return [
                'ok' => true,
                'text' => '{"tool":"tool_desconhecida","params":{"foo":"bar"}}',
                'error' => null,
                'meta' => [
                    'provider' => 'unknown_tool_test',
                    'model' => (string) ($options['model'] ?? 'unknown-tool-model'),
                ],
            ];
        }

        return [
            'ok' => true,
            'text' => 'Resposta sem ferramenta.',
            'error' => null,
            'meta' => [
                'provider' => 'unknown_tool_test',
                'model' => (string) ($options['model'] ?? 'unknown-tool-model'),
            ],
        ];
    }
}

