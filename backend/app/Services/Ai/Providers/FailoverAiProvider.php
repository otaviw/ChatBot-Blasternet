<?php

declare(strict_types=1);


namespace App\Services\Ai\Providers;

use Illuminate\Support\Facades\Log;
use Throwable;

class FailoverAiProvider implements AiProvider, AiStreamingProvider
{
    public function __construct(
        private readonly string $primaryProviderName,
        private readonly AiProvider $primaryProvider,
        private readonly string $fallbackProviderName,
        private readonly AiProvider $fallbackProvider,
    ) {}

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
        $primaryResult = $this->safeReply($this->primaryProvider, $messages, $options, $this->primaryProviderName);
        if ((bool) ($primaryResult['ok'] ?? false)) {
            return $this->withProviderMeta($primaryResult, $this->primaryProviderName);
        }

        $fallbackOptions = $this->optionsForFallback($options);
        $fallbackResult = $this->safeReply($this->fallbackProvider, $messages, $fallbackOptions, $this->fallbackProviderName);

        if ((bool) ($fallbackResult['ok'] ?? false)) {
            return $this->withFailoverMeta($fallbackResult, $primaryResult);
        }

        return $this->mergeFailedResults($primaryResult, $fallbackResult);
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
        $emittedChunks = 0;
        $primaryOnChunk = function (string $chunk) use ($onChunk, &$emittedChunks): void {
            $emittedChunks++;
            $onChunk($chunk);
        };

        $primaryResult = $this->safeStream($this->primaryProvider, $messages, $options, $primaryOnChunk, $this->primaryProviderName);
        if ((bool) ($primaryResult['ok'] ?? false)) {
            return $this->withProviderMeta($primaryResult, $this->primaryProviderName);
        }

        if ($emittedChunks > 0) {
            return $this->withProviderMeta($primaryResult, $this->primaryProviderName);
        }

        $fallbackOptions = $this->optionsForFallback($options);
        $fallbackResult = $this->safeStream(
            $this->fallbackProvider,
            $messages,
            $fallbackOptions,
            $onChunk,
            $this->fallbackProviderName
        );

        if ((bool) ($fallbackResult['ok'] ?? false)) {
            return $this->withFailoverMeta($fallbackResult, $primaryResult);
        }

        return $this->mergeFailedResults($primaryResult, $fallbackResult);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function safeReply(AiProvider $provider, array $messages, array $options, string $providerName): array
    {
        try {
            $result = $provider->reply($messages, $options);
            $normalized = is_array($result)
                ? $result
                : ['ok' => false, 'text' => null, 'error' => 'invalid_provider_result', 'meta' => []];

            return $this->withProviderMeta($normalized, $providerName);
        } catch (Throwable $exception) {
            Log::warning('ai.provider.reply_exception', [
                'provider' => $providerName,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'text' => null,
                'error' => 'provider_exception',
                'meta' => [
                    'provider' => $providerName,
                    'message' => 'Falha ao obter resposta da IA.',
                    'exception_message' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @param  callable(string):void  $onChunk
     * @return array<string, mixed>
     */
    private function safeStream(
        AiProvider $provider,
        array $messages,
        array $options,
        callable $onChunk,
        string $providerName
    ): array {
        try {
            if ($provider instanceof AiStreamingProvider) {
                $result = $provider->streamReply($messages, $options, $onChunk);
            } else {
                $result = $provider->reply($messages, $options);
                if ((bool) ($result['ok'] ?? false) && is_string($result['text'] ?? null) && $result['text'] !== '') {
                    $onChunk($result['text']);
                }
            }

            $normalized = is_array($result)
                ? $result
                : ['ok' => false, 'text' => null, 'error' => 'invalid_provider_result', 'meta' => []];

            return $this->withProviderMeta($normalized, $providerName);
        } catch (Throwable $exception) {
            Log::warning('ai.provider.stream_exception', [
                'provider' => $providerName,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'text' => null,
                'error' => 'provider_exception',
                'meta' => [
                    'provider' => $providerName,
                    'message' => 'Falha ao obter resposta da IA.',
                    'exception_message' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withProviderMeta(array $result, string $providerName): array
    {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $meta['provider'] = trim((string) ($meta['provider'] ?? '')) !== ''
            ? $meta['provider']
            : $providerName;

        $result['meta'] = $meta;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $fallbackResult
     * @param  array<string, mixed>  $primaryResult
     * @return array<string, mixed>
     */
    private function withFailoverMeta(array $fallbackResult, array $primaryResult): array
    {
        $meta = is_array($fallbackResult['meta'] ?? null) ? $fallbackResult['meta'] : [];
        $primaryMeta = is_array($primaryResult['meta'] ?? null) ? $primaryResult['meta'] : [];

        $meta['failover'] = [
            'from' => $this->primaryProviderName,
            'to' => $this->fallbackProviderName,
            'primary_error' => $primaryResult['error'] ?? null,
            'primary_message' => $primaryMeta['message'] ?? null,
        ];

        $fallbackResult['meta'] = $meta;

        Log::warning('ai.provider.failover_used', [
            'from' => $this->primaryProviderName,
            'to' => $this->fallbackProviderName,
            'primary_error' => $primaryResult['error'] ?? null,
        ]);

        return $fallbackResult;
    }

    /**
     * @param  array<string, mixed>  $primaryResult
     * @param  array<string, mixed>  $fallbackResult
     * @return array<string, mixed>
     */
    private function mergeFailedResults(array $primaryResult, array $fallbackResult): array
    {
        $meta = is_array($fallbackResult['meta'] ?? null) ? $fallbackResult['meta'] : [];
        $primaryMeta = is_array($primaryResult['meta'] ?? null) ? $primaryResult['meta'] : [];
        $meta['failover'] = [
            'from' => $this->primaryProviderName,
            'to' => $this->fallbackProviderName,
            'primary_error' => $primaryResult['error'] ?? null,
            'primary_message' => $primaryMeta['message'] ?? null,
            'fallback_error' => $fallbackResult['error'] ?? null,
        ];
        $fallbackResult['meta'] = $meta;

        return $fallbackResult;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function optionsForFallback(array $options): array
    {
        $fallbackOptions = $options;

        if ($this->fallbackProviderName === 'anthropic') {
            $fallbackOptions['model'] = trim((string) config('ai.providers.anthropic.model', ''));
        }

        return $fallbackOptions;
    }
}
