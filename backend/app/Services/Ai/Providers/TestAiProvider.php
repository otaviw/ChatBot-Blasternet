<?php

namespace App\Services\Ai\Providers;

class TestAiProvider implements AiProvider
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
        $prefix = trim((string) config('ai.providers.test.reply_prefix', '[AI TEST]'));
        $message = $this->resolveLastUserMessage($messages);
        $defaultText = $prefix !== '' ? $prefix : 'AI test provider response';
        $text = $message !== '' ? trim("{$prefix} {$message}") : $defaultText;

        return [
            'ok' => true,
            'text' => $text,
            'error' => null,
            'meta' => [
                'provider' => 'test',
                'model' => (string) ($options['model'] ?? config('ai.model', 'test-model')),
                'messages_count' => count($messages),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function resolveLastUserMessage(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if (! is_array($message)) {
                continue;
            }

            $role = mb_strtolower(trim((string) ($message['role'] ?? '')));
            if ($role !== '' && $role !== 'user') {
                continue;
            }

            $content = trim((string) ($message['content'] ?? $message['text'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }
}
