<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CircuitBreakerAiProvider implements AiProvider, AiStreamingProvider
{
    public function __construct(
        private readonly string $providerName,
        private readonly AiProvider $provider,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array{ok:bool,text:?string,error:mixed,meta:array<string,mixed>|null}
     */
    public function reply(array $messages, array $options = []): array
    {
        $openUntil = $this->openUntilTimestamp();
        $now = CarbonImmutable::now()->getTimestamp();

        if ($openUntil > $now) {
            return $this->openCircuitResult($openUntil);
        }

        try {
            $result = $this->provider->reply($messages, $options);
        } catch (Throwable $exception) {
            $this->registerFailure($exception->getMessage());

            return $this->failureResult('Falha ao obter resposta da IA.', $exception->getMessage());
        }

        $normalized = is_array($result)
            ? $result
            : ['ok' => false, 'text' => null, 'error' => 'invalid_provider_result', 'meta' => []];

        if ((bool) ($normalized['ok'] ?? false)) {
            $this->resetState();

            return $normalized;
        }

        $message = is_array($normalized['meta'] ?? null)
            ? (string) ($normalized['meta']['message'] ?? '')
            : '';
        $this->registerFailure($message);

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @param  callable(string):void  $onChunk
     * @return array{ok:bool,text:?string,error:mixed,meta:array<string,mixed>|null}
     */
    public function streamReply(array $messages, array $options, callable $onChunk): array
    {
        $openUntil = $this->openUntilTimestamp();
        $now = CarbonImmutable::now()->getTimestamp();

        if ($openUntil > $now) {
            return $this->openCircuitResult($openUntil);
        }

        try {
            if ($this->provider instanceof AiStreamingProvider) {
                $result = $this->provider->streamReply($messages, $options, $onChunk);
            } else {
                $result = $this->provider->reply($messages, $options);
                if ((bool) ($result['ok'] ?? false) && is_string($result['text'] ?? null) && $result['text'] !== '') {
                    $onChunk($result['text']);
                }
            }
        } catch (Throwable $exception) {
            $this->registerFailure($exception->getMessage());

            return $this->failureResult('Falha ao obter resposta da IA.', $exception->getMessage());
        }

        $normalized = is_array($result)
            ? $result
            : ['ok' => false, 'text' => null, 'error' => 'invalid_provider_result', 'meta' => []];

        if ((bool) ($normalized['ok'] ?? false)) {
            $this->resetState();

            return $normalized;
        }

        $message = is_array($normalized['meta'] ?? null)
            ? (string) ($normalized['meta']['message'] ?? '')
            : '';
        $this->registerFailure($message);

        return $normalized;
    }

    private function registerFailure(string $message): void
    {
        $threshold = max(1, (int) config('ai.circuit_breaker.failure_threshold', 5));
        $cooldownSeconds = max(1, (int) config('ai.circuit_breaker.cooldown_seconds', 60));
        $cacheSeconds = max($cooldownSeconds * 2, 120);

        $failures = (int) Cache::increment($this->failuresKey());
        if ($failures === 1) {
            Cache::put($this->failuresKey(), 1, now()->addSeconds($cacheSeconds));
        }

        if ($failures < $threshold) {
            return;
        }

        $openUntil = CarbonImmutable::now()->addSeconds($cooldownSeconds)->getTimestamp();
        Cache::put($this->openedUntilKey(), $openUntil, now()->addSeconds($cooldownSeconds + 10));
        Cache::forget($this->failuresKey());

        Log::warning('ai.provider.circuit_opened', [
            'provider' => $this->providerName,
            'failure_threshold' => $threshold,
            'cooldown_seconds' => $cooldownSeconds,
            'open_until' => $openUntil,
            'message' => $message,
        ]);
    }

    private function resetState(): void
    {
        Cache::forget($this->failuresKey());
        Cache::forget($this->openedUntilKey());
    }

    private function openUntilTimestamp(): int
    {
        return (int) Cache::get($this->openedUntilKey(), 0);
    }

    private function failuresKey(): string
    {
        return sprintf('ai:provider:circuit:%s:failures', $this->providerName);
    }

    private function openedUntilKey(): string
    {
        return sprintf('ai:provider:circuit:%s:opened_until', $this->providerName);
    }

    /**
     * @return array{ok:false,text:null,error:string,meta:array<string,mixed>}
     */
    private function openCircuitResult(int $openUntil): array
    {
        return [
            'ok' => false,
            'text' => null,
            'error' => 'ai_provider_circuit_open',
            'meta' => [
                'provider' => $this->providerName,
                'message' => 'Provider de IA temporariamente bloqueado por instabilidade.',
                'open_until' => $openUntil,
            ],
        ];
    }

    /**
     * @return array{ok:false,text:null,error:string,meta:array<string,mixed>}
     */
    private function failureResult(string $message, string $exception): array
    {
        return [
            'ok' => false,
            'text' => null,
            'error' => 'provider_exception',
            'meta' => [
                'provider' => $this->providerName,
                'message' => $message,
                'exception_message' => $exception,
            ],
        ];
    }
}

