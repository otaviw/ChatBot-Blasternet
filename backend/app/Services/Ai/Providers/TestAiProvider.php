<?php

namespace App\Services\Ai\Providers;

class TestAiProvider implements AiProvider, AiStreamingProvider
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
     * @param  array<string, mixed>  $options
     * @param  callable(string):void  $onChunk
     * @return array{
     *     ok:bool,
     *     text:?string,
     *     error:mixed,
     *     meta:array<string, mixed>|null
     * }
     */
    public function streamReply(array $messages, array $options, callable $onChunk): array
    {
        $result = $this->reply($messages, $options);

        if (! (bool) ($result['ok'] ?? false) || $result['text'] === null) {
            return $result;
        }

        // Fake streaming: emit word by word to simulate token-by-token output
        $words = explode(' ', $result['text']);
        foreach ($words as $index => $word) {
            $chunk = $index === 0 ? $word : ' '.$word;
            $onChunk($chunk);
        }

        return $result;
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
