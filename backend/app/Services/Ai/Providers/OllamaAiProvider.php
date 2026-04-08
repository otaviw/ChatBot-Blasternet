<?php

namespace App\Services\Ai\Providers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class OllamaAiProvider implements AiProvider, AiStreamingProvider
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
        $model = trim((string) ($options['model'] ?? config('ai.model', '')));
        if ($model === '') {
            return $this->errorResult('Modelo da IA nao configurado para o provider Ollama.', [
                'provider' => 'ollama',
            ]);
        }

        $baseUrl = rtrim((string) config('ai.providers.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $chatPath = '/'.ltrim((string) config('ai.providers.ollama.chat_path', '/api/chat'), '/');
        $url = $baseUrl.$chatPath;
        $timeoutSeconds = $this->resolveTimeoutSeconds($options);

        $payload = [
            'model' => $model,
            'stream' => false,
            'messages' => $this->normalizeMessages($messages),
        ];

        $providerOptions = $this->resolveProviderOptions($options);
        if ($providerOptions !== []) {
            $payload['options'] = $providerOptions;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($timeoutSeconds)
                ->connectTimeout(min($timeoutSeconds, 10))
                ->post($url, $payload);
        } catch (Throwable $exception) {
            $exceptionMessage = trim($exception->getMessage());
            $normalizedException = mb_strtolower($exceptionMessage);
            $isTimeout = str_contains($normalizedException, 'timed out')
                || str_contains($normalizedException, 'timeout');

            $message = $isTimeout
                ? "Ollama demorou mais que {$timeoutSeconds}s para responder. Aumente AI_REQUEST_TIMEOUT_MS e/ou reduza AI_MAX_RESPONSE_TOKENS."
                : "Falha ao conectar no Ollama em {$url}. Verifique se o servidor esta ativo.";

            return $this->errorResult(
                $message,
                [
                    'provider' => 'ollama',
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

            $responseMessage = '';
            if (is_array($responseJson)) {
                $responseMessage = trim((string) ($responseJson['error'] ?? $responseJson['message'] ?? ''));
            }

            if ($responseMessage === '') {
                $responseMessage = $responseBody;
            }

            $message = $responseMessage !== ''
                ? "Ollama retornou erro HTTP {$statusCode}: {$responseMessage}"
                : "Ollama retornou erro HTTP {$statusCode}.";

            return $this->errorResult($message, [
                'provider' => 'ollama',
                'model' => $model,
                'url' => $url,
                'status' => $statusCode,
            ]);
        }

        $responseJson = $response->json();
        $text = trim((string) data_get($responseJson, 'message.content', data_get($responseJson, 'response', '')));

        if ($text === '') {
            return $this->errorResult('Ollama nao retornou conteudo de resposta.', [
                'provider' => 'ollama',
                'model' => $model,
                'url' => $url,
            ]);
        }

        $promptTokens = (int) data_get($responseJson, 'prompt_eval_count', 0);
        $completionTokens = (int) data_get($responseJson, 'eval_count', 0);
        $totalTokens = $promptTokens + $completionTokens;

        return [
            'ok' => true,
            'text' => $text,
            'error' => null,
            'meta' => [
                'provider' => 'ollama',
                'model' => $model,
                'usage' => [
                    'prompt_tokens' => $promptTokens > 0 ? $promptTokens : null,
                    'completion_tokens' => $completionTokens > 0 ? $completionTokens : null,
                    'total_tokens' => $totalTokens > 0 ? $totalTokens : null,
                ],
                'done_reason' => data_get($responseJson, 'done_reason'),
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
        $model = trim((string) ($options['model'] ?? config('ai.model', '')));
        if ($model === '') {
            return $this->errorResult('Modelo da IA nao configurado para o provider Ollama.', [
                'provider' => 'ollama',
            ]);
        }

        $baseUrl = rtrim((string) config('ai.providers.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $chatPath = '/'.ltrim((string) config('ai.providers.ollama.chat_path', '/api/chat'), '/');
        $url = $baseUrl.$chatPath;
        $timeoutSeconds = $this->resolveTimeoutSeconds($options);

        $payload = [
            'model' => $model,
            'stream' => true,
            'messages' => $this->normalizeMessages($messages),
        ];

        $providerOptions = $this->resolveProviderOptions($options);
        if ($providerOptions !== []) {
            $payload['options'] = $providerOptions;
        }

        try {
            $client = new GuzzleClient();
            $response = $client->post($url, [
                'json' => $payload,
                'stream' => true,
                'timeout' => $timeoutSeconds,
                'connect_timeout' => min($timeoutSeconds, 10),
            ]);
        } catch (GuzzleConnectException $exception) {
            return $this->errorResult(
                "Falha ao conectar no Ollama em {$url}. Verifique se o servidor esta ativo.",
                [
                    'provider' => 'ollama',
                    'model' => $model,
                    'url' => $url,
                    'exception' => $exception->getMessage(),
                ]
            );
        } catch (GuzzleRequestException $exception) {
            $exceptionMessage = trim($exception->getMessage());
            $normalizedException = mb_strtolower($exceptionMessage);
            $isTimeout = str_contains($normalizedException, 'timed out')
                || str_contains($normalizedException, 'timeout');

            $message = $isTimeout
                ? "Ollama demorou mais que {$timeoutSeconds}s para responder. Aumente AI_REQUEST_TIMEOUT_MS e/ou reduza AI_MAX_RESPONSE_TOKENS."
                : "Erro na requisicao ao Ollama em {$url}: {$exceptionMessage}";

            return $this->errorResult($message, [
                'provider' => 'ollama',
                'model' => $model,
                'url' => $url,
                'exception' => $exceptionMessage,
            ]);
        } catch (Throwable $exception) {
            return $this->errorResult(
                "Erro inesperado ao conectar no Ollama em {$url}.",
                [
                    'provider' => 'ollama',
                    'model' => $model,
                    'url' => $url,
                    'exception' => $exception->getMessage(),
                ]
            );
        }

        $body = $response->getBody();
        $buffer = '';
        $fullText = '';
        $lastData = null;

        while (! $body->eof()) {
            $read = $body->read(4096);
            if ($read === '' || $read === false) {
                break;
            }

            $buffer .= $read;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);
                if (! is_array($data)) {
                    continue;
                }

                $lastData = $data;
                $chunk = (string) data_get($data, 'message.content', '');
                if ($chunk !== '') {
                    $fullText .= $chunk;
                    $onChunk($chunk);
                }
            }
        }

        // Process any remaining buffer after stream ends
        $remaining = trim($buffer);
        if ($remaining !== '') {
            $data = json_decode($remaining, true);
            if (is_array($data)) {
                $lastData = $data;
                $chunk = (string) data_get($data, 'message.content', '');
                if ($chunk !== '') {
                    $fullText .= $chunk;
                    $onChunk($chunk);
                }
            }
        }

        if ($fullText === '') {
            return $this->errorResult('Ollama nao retornou conteudo de resposta.', [
                'provider' => 'ollama',
                'model' => $model,
                'url' => $url,
            ]);
        }

        $promptTokens = (int) data_get($lastData, 'prompt_eval_count', 0);
        $completionTokens = (int) data_get($lastData, 'eval_count', 0);
        $totalTokens = $promptTokens + $completionTokens;

        return [
            'ok' => true,
            'text' => $fullText,
            'error' => null,
            'meta' => [
                'provider' => 'ollama',
                'model' => $model,
                'usage' => [
                    'prompt_tokens' => $promptTokens > 0 ? $promptTokens : null,
                    'completion_tokens' => $completionTokens > 0 ? $completionTokens : null,
                    'total_tokens' => $totalTokens > 0 ? $totalTokens : null,
                ],
                'done_reason' => data_get($lastData, 'done_reason'),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array{role:string, content:string}>
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
     * @param  array<string, mixed>  $options
     * @return array<string, int|float>
     */
    private function resolveProviderOptions(array $options): array
    {
        $providerOptions = [];

        $temperature = $this->normalizeOptionalFloat($options['temperature'] ?? null);
        if ($temperature !== null) {
            $providerOptions['temperature'] = $temperature;
        }

        $maxResponseTokens = $this->normalizeOptionalInt($options['max_response_tokens'] ?? null);
        if ($maxResponseTokens !== null) {
            $providerOptions['num_predict'] = $maxResponseTokens;
        }

        return $providerOptions;
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
     * @param  array<string, mixed>  $meta
     * @return array{
     *     ok:false,
     *     text:null,
     *     error:string,
     *     meta:array<string, mixed>
     * }
     */
    private function errorResult(string $message, array $meta = []): array
    {
        return [
            'ok' => false,
            'text' => null,
            'error' => 'ollama_provider_error',
            'meta' => array_merge($meta, [
                'message' => $message,
            ]),
        ];
    }
}
