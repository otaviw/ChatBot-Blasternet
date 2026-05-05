<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\AiUsageLog;
use Throwable;

/**
 * Serviço central de observabilidade para chamadas de IA.
 *
 * Responsabilidades:
 *  - Registrar e atualizar métricas por requisição em `ai_usage_logs`
 *  - Normalizar erros em categorias padronizadas (timeout, provider_unavailable…)
 *  - Expor método `measure()` para cronometrar chamadas ao provider com
 *    captura automática de exceções
 *
 * Sem dados sensíveis: conteúdo das mensagens, prompts e respostas nunca são
 * gravados aqui — apenas metadados estruturais (provider, latência, tokens).
 */
class AiMetricsService
{
    /**
     * Cria um novo registro de métrica independente (para features sem AiUsageLog prévio,
     * como conversation_suggestion e chatbot).
     */
    public function record(
        int $companyId,
        ?int $userId,
        ?int $conversationId,
        string $provider,
        ?string $model,
        string $feature,
        string $status,
        int $responseTimeMs,
        ?int $tokensUsed,
        ?string $errorType = null
    ): AiUsageLog {
        return AiUsageLog::query()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'type' => $this->featureToType($feature),
            'provider' => mb_substr(trim($provider), 0, 60),
            'model' => $model !== null ? mb_substr(trim($model), 0, 120) : null,
            'feature' => $this->normalizeFeature($feature),
            'status' => $status === AiUsageLog::STATUS_ERROR ? AiUsageLog::STATUS_ERROR : AiUsageLog::STATUS_OK,
            'message_length' => 0,
            'tokens_used' => $tokensUsed !== null ? max(0, $tokensUsed) : null,
            'response_time_ms' => max(0, $responseTimeMs),
            'error_type' => $errorType,
            'created_at' => now(),
        ]);
    }

    /**
     * Atualiza um AiUsageLog existente (criado em consumeInternalChat) com os campos
     * de observabilidade preenchidos após a chamada ao provider.
     *
     * @param  array<string, mixed>  $providerResult
     */
    public function updateFromProviderResult(
        AiUsageLog $log,
        string $provider,
        ?string $model,
        string $feature,
        array $providerResult,
        int $responseTimeMs,
        ?int $tokensUsed = null
    ): void {
        $ok = (bool) ($providerResult['ok'] ?? false);
        $status = $ok ? AiUsageLog::STATUS_OK : AiUsageLog::STATUS_ERROR;
        $errorType = ! $ok ? $this->normalizeErrorType($providerResult['error'] ?? null) : null;

        $log->provider = mb_substr(trim($provider), 0, 60);
        $log->model = $model !== null ? mb_substr(trim($model), 0, 120) : null;
        $log->feature = $this->normalizeFeature($feature);
        $log->status = $status;
        $log->response_time_ms = max(0, $responseTimeMs);
        $log->error_type = $errorType;

        if ($tokensUsed !== null) {
            $log->tokens_used = max(0, $tokensUsed);
        }

        $log->save();
    }

    /**
     * Cronometra uma chamada callable ao provider, captura exceções e retorna
     * um array com o resultado e os metadados de observabilidade.
     *
     * @template T
     * @param  callable(): T  $call
     * @return array{result: T|null, response_time_ms: int, exception: Throwable|null}
     */
    public function measure(callable $call): array
    {
        $startedAt = microtime(true);
        $exception = null;
        $result = null;

        try {
            $result = $call();
        } catch (Throwable $e) {
            $exception = $e;
        }

        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'result' => $result,
            'response_time_ms' => $responseTimeMs,
            'exception' => $exception,
        ];
    }

    /**
     * Normaliza a string de erro do provider para uma categoria padronizada.
     */
    public static function normalizeErrorType(mixed $error, ?Throwable $exception = null): string
    {
        if ($exception !== null) {
            $exClass = get_class($exception);
            $exMsg = mb_strtolower($exception->getMessage());
            if (str_contains($exMsg, 'timeout') || str_contains($exMsg, 'timed out')
                || str_contains($exClass, 'Timeout') || str_contains($exClass, 'ConnectException')) {
                return AiUsageLog::ERROR_TIMEOUT;
            }
            if (str_contains($exMsg, 'connect') || str_contains($exMsg, 'refused')
                || str_contains($exMsg, 'unreachable') || str_contains($exClass, 'ConnectionException')) {
                return AiUsageLog::ERROR_PROVIDER_UNAVAILABLE;
            }
        }

        if (! is_string($error) || trim($error) === '') {
            return AiUsageLog::ERROR_UNKNOWN;
        }

        $normalized = mb_strtolower(trim($error));

        if (str_contains($normalized, 'timeout') || str_contains($normalized, 'timed_out')
            || str_contains($normalized, 'timed out')) {
            return AiUsageLog::ERROR_TIMEOUT;
        }

        if (str_contains($normalized, 'connect') || str_contains($normalized, 'unavailable')
            || str_contains($normalized, 'refused') || str_contains($normalized, 'unreachable')
            || $normalized === 'provider_exception') {
            return AiUsageLog::ERROR_PROVIDER_UNAVAILABLE;
        }

        if (str_contains($normalized, 'validation') || str_contains($normalized, 'invalid')
            || str_contains($normalized, '422') || str_contains($normalized, '400')
            || str_contains($normalized, 'bad_request')) {
            return AiUsageLog::ERROR_VALIDATION;
        }

        return AiUsageLog::ERROR_UNKNOWN;
    }

    private function normalizeFeature(string $feature): string
    {
        $normalized = mb_strtolower(trim($feature));

        return in_array($normalized, AiUsageLog::ALLOWED_FEATURES, true)
            ? $normalized
            : AiUsageLog::FEATURE_INTERNAL_CHAT;
    }

    private function featureToType(string $feature): string
    {
        return match ($this->normalizeFeature($feature)) {
            AiUsageLog::FEATURE_CHATBOT => AiUsageLog::TYPE_CHATBOT,
            default => AiUsageLog::TYPE_INTERNAL_CHAT,
        };
    }
}
