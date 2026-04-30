<?php

declare(strict_types=1);


namespace App\Services\Ai\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnthropicAiProvider implements AiProvider
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
        $apiKey = trim((string) config('ai.providers.anthropic.api_key', ''));
        if ($apiKey === '') {
            return $this->errorResult('Chave da Anthropic nao configurada.', [
                'provider' => 'anthropic',
            ]);
        }

        $model = trim((string) ($options['model'] ?? config('ai.providers.anthropic.model', '')));
        if ($model === '') {
            return $this->errorResult('Modelo da Anthropic nao configurado.', [
                'provider' => 'anthropic',
            ]);
        }

        $normalized = $this->normalizeMessages($messages);
        if ($normalized['messages'] === []) {
            return $this->errorResult('Contexto de mensagens vazio para Anthropic.', [
                'provider' => 'anthropic',
                'model' => $model,
            ]);
        }

        $baseUrl = rtrim((string) config('ai.providers.anthropic.base_url', 'https://api.anthropic.com'), '/');
        $messagesPath = '/'.ltrim((string) config('ai.providers.anthropic.messages_path', '/v1/messages'), '/');
        $url = $baseUrl.$messagesPath;
        $timeoutSeconds = $this->resolveTimeoutSeconds($options);
        $version = trim((string) config('ai.providers.anthropic.version', '2023-06-01'));

        $payload = [
            'model' => $model,
            'messages' => $normalized['messages'],
            'max_tokens' => $this->resolveMaxTokens($options),
        ];

        if ($normalized['system'] !== '') {
            $payload['system'] = $normalized['system'];
        }

        $temperature = $this->normalizeOptionalFloat($options['temperature'] ?? null);
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => $version,
                ])
                ->timeout($timeoutSeconds)
                ->connectTimeout(min($timeoutSeconds, 10))
                ->post($url, $payload);
        } catch (Throwable $exception) {
            $exceptionMessage = trim($exception->getMessage());
            $normalizedException = mb_strtolower($exceptionMessage);
            $isTimeout = str_contains($normalizedException, 'timed out')
                || str_contains($normalizedException, 'timeout');

            if ($isTimeout) {
                Log::warning('ai.provider.timeout', [
                    'provider' => 'anthropic',
                    'model' => $model,
                    'url' => $url,
                    'timeout_seconds' => $timeoutSeconds,
                    'exception' => $exceptionMessage,
                ]);

                return $this->timeoutResult($model, $url, $timeoutSeconds, $exceptionMessage);
            }

            return $this->errorResult(
                "Falha ao conectar na Anthropic em {$url}.",
                [
                    'provider' => 'anthropic',
                    'model' => $model,
                    'url' => $url,
                    'exception' => $exceptionMessage,
                ]
            );
        }

        if (! $response->successful()) {
            $statusCode = $response->status();
            $responseJson = $response->json();
            $responseBody = trim((string) $response->body());

            $message = trim((string) data_get($responseJson, 'error.message', data_get($responseJson, 'message', '')));
            if ($message === '') {
                $message = $responseBody;
            }

            $humanMessage = $message !== ''
                ? "Anthropic retornou erro HTTP {$statusCode}: {$message}"
                : "Anthropic retornou erro HTTP {$statusCode}.";

            return $this->errorResult($humanMessage, [
                'provider' => 'anthropic',
                'model' => $model,
                'url' => $url,
                'status' => $statusCode,
            ]);
        }

        $responseJson = $response->json();
        $text = $this->extractTextFromResponse($responseJson);

        if ($text === '') {
            return $this->errorResult('Anthropic nao retornou conteudo de resposta.', [
                'provider' => 'anthropic',
                'model' => $model,
                'url' => $url,
            ]);
        }

        $promptTokens = (int) data_get($responseJson, 'usage.input_tokens', 0);
        $completionTokens = (int) data_get($responseJson, 'usage.output_tokens', 0);
        $totalTokens = $promptTokens + $completionTokens;

        return [
            'ok' => true,
            'text' => $text,
            'error' => null,
            'meta' => [
                'provider' => 'anthropic',
                'model' => $model,
                'usage' => [
                    'prompt_tokens' => $promptTokens > 0 ? $promptTokens : null,
                    'completion_tokens' => $completionTokens > 0 ? $completionTokens : null,
                    'total_tokens' => $totalTokens > 0 ? $totalTokens : null,
                ],
                'stop_reason' => data_get($responseJson, 'stop_reason'),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{
     *     system:string,
     *     messages:array<int, array{role:string,content:string}>
     * }
     */
    private function normalizeMessages(array $messages): array
    {
        $normalizedMessages = [];
        $systemParts = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = mb_strtolower(trim((string) ($message['role'] ?? 'user')));
            $content = trim((string) ($message['content'] ?? $message['text'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($role === 'system') {
                $systemParts[] = $content;
                continue;
            }

            if (! in_array($role, ['user', 'assistant'], true)) {
                $role = 'user';
            }

            $normalizedMessages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return [
            'system' => implode("\n\n", $systemParts),
            'messages' => $normalizedMessages,
        ];
    }

    /**
     * @param  array<string, mixed>  $responseJson
     */
    private function extractTextFromResponse(array $responseJson): string
    {
        $content = data_get($responseJson, 'content');
        if (! is_array($content)) {
            return trim((string) data_get($responseJson, 'completion', ''));
        }

        $parts = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if ((string) ($block['type'] ?? '') !== 'text') {
                continue;
            }

            $text = trim((string) ($block['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveTimeoutSeconds(array $options): int
    {
        $timeoutMs = $this->normalizeOptionalInt($options['request_timeout_ms'] ?? config('ai.request_timeout_ms', 30000));
        if ($timeoutMs === null) {
            return 30;
        }

        return max(1, (int) ceil($timeoutMs / 1000));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveMaxTokens(array $options): int
    {
        $configured = $this->normalizeOptionalInt(
            $options['max_response_tokens'] ?? config('ai.providers.anthropic.max_response_tokens', 1024)
        );

        return $configured ?? 1024;
    }

    private function normalizeOptionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function normalizeOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    /**
     * @return array{ok:false,text:null,error:string,meta:array<string,mixed>}
     */
    private function timeoutResult(string $model, string $url, int $timeoutSeconds, string $exceptionMessage): array
    {
        return [
            'ok' => false,
            'text' => null,
            'error' => 'anthropic_timeout',
            'meta' => [
                'provider' => 'anthropic',
                'model' => $model,
                'url' => $url,
                'timeout_seconds' => $timeoutSeconds,
                'exception' => $exceptionMessage,
                'message' => 'Assistente temporariamente indisponivel. Tente novamente em instantes.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{
     *     ok:false,
     *     text:null,
     *     error:string,
     *     meta:array<string,mixed>
     * }
     */
    private function errorResult(string $message, array $meta = []): array
    {
        return [
            'ok' => false,
            'text' => null,
            'error' => 'anthropic_provider_error',
            'meta' => array_merge($meta, [
                'message' => $message,
            ]),
        ];
    }
}
