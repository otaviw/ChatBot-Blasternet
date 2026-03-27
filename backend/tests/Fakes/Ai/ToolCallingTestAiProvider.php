<?php

namespace Tests\Fakes\Ai;

use App\Services\Ai\Providers\AiProvider;

class ToolCallingTestAiProvider implements AiProvider
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
        self::$calls++;

        if (self::$calls === 1) {
            return [
                'ok' => true,
                'text' => "```json\n{\"tool\":\"get_customer_by_phone\",\"params\":{\"phone\":\"+55 (11) 98888-7777\"}}\n```",
                'error' => null,
                'meta' => [
                    'provider' => 'tool_calling_test',
                    'model' => (string) ($options['model'] ?? 'tool-call-model'),
                ],
            ];
        }

        $toolResult = $this->extractToolResult($messages);
        $name = trim((string) ($toolResult['name'] ?? ''));
        $plan = trim((string) ($toolResult['plan'] ?? ''));

        $parts = ['Resposta final com ferramenta'];
        if ($name !== '') {
            $parts[] = "cliente={$name}";
        }
        if ($plan !== '') {
            $parts[] = "plano={$plan}";
        }

        return [
            'ok' => true,
            'text' => implode(' | ', $parts),
            'error' => null,
            'meta' => [
                'provider' => 'tool_calling_test',
                'model' => (string) ($options['model'] ?? 'tool-call-model'),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function extractToolResult(array $messages): array
    {
        foreach ($messages as $message) {
            $content = trim((string) ($message['content'] ?? ''));
            if (! str_starts_with($content, 'Resultado da ferramenta get_customer_by_phone:')) {
                continue;
            }

            $pieces = explode("\n", $content, 2);
            $json = trim((string) ($pieces[1] ?? ''));
            $decoded = json_decode($json, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

