<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenRouterAiProvider implements AiProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array{ok:bool,text:?string,error:mixed,meta:array<string,mixed>|null}
     */
    public function reply(array $messages, array $options = []): array
    {
        $apiKey = trim((string) config('ai.providers.openrouter.api_key', ''));
        if ($apiKey === '') {
            return $this->errorResult('Chave do OpenRouter nao configurada.', [
                'provider' => 'openrouter',
            ]);
        }

        $model = trim((string) ($options['model'] ?? config('ai.providers.openrouter.model', 'openrouter/free')));
        if ($model === '') {
            return $this->errorResult('Modelo do OpenRouter nao configurado.', [
                'provider' => 'openrouter',
            ]);
        }

        $normalizedMessages = $this->normalizeMessages($messages);
        if ($normalizedMessages === []) {
            return $this->errorResult('Contexto de mensagens vazio para OpenRouter.', [
                'provider' => 'openrouter',
                'model' => $model,
            ]);
        }

        $baseUrl = rtrim((string) config('ai.providers.openrouter.base_url', 'https://openrouter.ai'), '/');
        $chatPath = '/'.ltrim((string) config('ai.providers.openrouter.chat_path', '/api/v1/chat/completions'), '/');
        $url = $baseUrl.$chatPath;
        $timeoutSeconds = $this->resolveTimeoutSeconds($options);

        $payload = [
            'model' => $model,
            'messages' => $normalizedMessages,
        ];

        $temperature = $this->normalizeOptionalFloat($options['temperature'] ?? null);
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        $maxTokens = $this->normalizeOptionalInt($options['max_response_tokens'] ?? null);
        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->withHeaders($this->headers($apiKey))
                ->timeout($timeoutSeconds)
                ->connectTimeout(min($timeoutSeconds, 10));

            $response = $request->post($url, $payload);
        } catch (Throwable $exception) {
            $exceptionMessage = trim($exception->getMessage());
            $normalizedException = mb_strtolower($exceptionMessage);
            $isTimeout = str_contains($normalizedException, 'timed out')
                || str_contains($normalizedException, 'timeout');

            if ($isTimeout) {
                Log::warning('ai.provider.timeout', [
                    'provider' => 'openrouter',
                    'model' => $model,
                    'url' => $url,
                    'timeout_seconds' => $timeoutSeconds,
                    'exception' => $exceptionMessage,
                ]);

                return $this->timeoutResult($model, $url, $timeoutSeconds, $exceptionMessage);
            }

            return $this->errorResult(
                "Falha ao conectar no OpenRouter em {$url}.",
                [
                    'provider' => 'openrouter',
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
                ? "OpenRouter retornou erro HTTP {$statusCode}: {$message}"
                : "OpenRouter retornou erro HTTP {$statusCode}.";

            return $this->errorResult($humanMessage, [
                'provider' => 'openrouter',
                'model' => $model,
                'url' => $url,
                'status' => $statusCode,
            ]);
        }

        $responseJson = $response->json();
        $text = trim((string) data_get($responseJson, 'choices.0.message.content', ''));
        if ($text === '') {
            return $this->errorResult('OpenRouter nao retornou conteudo de resposta.', [
                'provider' => 'openrouter',
                'model' => $model,
                'url' => $url,
            ]);
        }

        $promptTokens = (int) data_get($responseJson, 'usage.prompt_tokens', 0);
        $completionTokens = (int) data_get($responseJson, 'usage.completion_tokens', 0);
        $totalTokens = (int) data_get($responseJson, 'usage.total_tokens', $promptTokens + $completionTokens);

        return [
            'ok' => true,
            'text' => $text,
            'error' => null,
            'meta' => [
                'provider' => 'openrouter',
                'model' => (string) data_get($responseJson, 'model', $model),
                'usage' => [
                    'prompt_tokens' => $promptTokens > 0 ? $promptTokens : null,
                    'completion_tokens' => $completionTokens > 0 ? $completionTokens : null,
                    'total_tokens' => $totalTokens > 0 ? $totalTokens : null,
                ],
                'finish_reason' => data_get($responseJson, 'choices.0.finish_reason'),
                'id' => data_get($responseJson, 'id'),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array{role:string,content:string}>
     */
    private function normalizeMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = mb_strtolower(trim((string) ($message['role'] ?? 'user')));
            if (! in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }

            $content = trim((string) ($message['content'] ?? $message['text'] ?? ''));
            if ($content === '') {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $apiKey): array
    {
        $headers = [
            'Authorization' => "Bearer {$apiKey}",
        ];

        $siteUrl = trim((string) config('ai.providers.openrouter.site_url', ''));
        if ($siteUrl !== '') {
            $headers['HTTP-Referer'] = $siteUrl;
        }

        $appName = trim((string) config('ai.providers.openrouter.app_name', ''));
        if ($appName !== '') {
            $headers['X-Title'] = $appName;
        }

        return $headers;
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

        return $this->capTimeoutToExecutionLimit(max(1, (int) ceil($timeoutMs / 1000)));
    }

    private function capTimeoutToExecutionLimit(int $timeoutSeconds): int
    {
        $maxExecutionSeconds = $this->normalizeOptionalInt(ini_get('max_execution_time'));
        if ($maxExecutionSeconds === null || $maxExecutionSeconds <= 0) {
            return $timeoutSeconds;
        }

        return min($timeoutSeconds, max(1, $maxExecutionSeconds - 10));
    }

    private function normalizeOptionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function normalizeOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
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
            'error' => 'openrouter_timeout',
            'meta' => [
                'provider' => 'openrouter',
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
     * @return array{ok:false,text:null,error:string,meta:array<string,mixed>}
     */
    private function errorResult(string $message, array $meta = []): array
    {
        return [
            'ok' => false,
            'text' => null,
            'error' => 'openrouter_provider_error',
            'meta' => array_merge($meta, [
                'message' => $message,
            ]),
        ];
    }
}
