<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Support\IxcUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IxcApiService
{
    private const BREAKER_THRESHOLD = 5;
    private const BREAKER_WINDOW_SECONDS = 120;
    private const BREAKER_OPEN_SECONDS = 90;
    private const DEBUG_BODY_TRUNCATE = 4000;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(Company $company, string $resource, array $params, string $method = 'get'): array
    {
        if (! $company->hasIxcIntegration()) {
            throw new RuntimeException('Integração IXC não está habilitada para esta empresa.');
        }

        $baseUrl = rtrim((string) ($company->ixc_base_url ?? ''), '/');
        $token = trim((string) ($company->ixc_api_token ?? ''));
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Credenciais IXC incompletas para esta empresa.');
        }
        if (! IxcUrlGuard::isSafeBaseUrl($baseUrl, (bool) config('ixc.allow_private_hosts', false))) {
            throw new RuntimeException('URL base da IXC inválida ou não permitida.');
        }

        $normalizedMethod = strtolower(trim($method));
        $useCache = $normalizedMethod === 'get';
        $cacheKey = $this->buildCacheKey((int) $company->id, $resource, $params, $normalizedMethod);
        $breakerState = $this->breakerStateKey((int) $company->id, $resource, $normalizedMethod);
        if (Cache::get($breakerState) === 'open') {
            $this->registerBreakerOpen($company, $resource, $normalizedMethod);
            throw new RuntimeException('Integração IXC temporariamente indisponível para esta empresa. Tente novamente em instantes.');
        }

        $executor = function () use ($baseUrl, $token, $resource, $params, $company, $normalizedMethod, $breakerState): array {
            $attemptsPerMode = $normalizedMethod === 'get' ? 2 : 1;
            $lastException = null;
            $started = microtime(true);
            $url = $baseUrl . '/' . ltrim($resource, '/');
            $requestModes = $this->resolveRequestModes($resource, $normalizedMethod);
            try {
                foreach ($requestModes as $modeIndex => $mode) {
                    for ($i = 0; $i < $attemptsPerMode; $i++) {
                        $headers = $this->buildRequestHeaders($token, $mode);
                        try {
                            $client = Http::timeout(max(5, min(60, (int) ($company->ixc_timeout_seconds ?? 15))))
                                ->acceptJson()
                                ->withOptions(['verify' => ! (bool) $company->ixc_self_signed])
                                ->withHeaders($headers);

                            $response = match ($normalizedMethod) {
                                'post' => $client->post($url, $params),
                                default => $client->get($url, $params),
                            };
                        } catch (ConnectionException) {
                            $lastException = new RuntimeException('Falha de conexão com a API IXC.');
                            $this->logDebugAttempt($company, $resource, $url, $normalizedMethod, $params, $headers, $mode, null, null, null, false, false, $lastException->getMessage());
                            continue;
                        } catch (\Throwable) {
                            $lastException = new RuntimeException('Erro inesperado ao consultar a API IXC.');
                            $this->logDebugAttempt($company, $resource, $url, $normalizedMethod, $params, $headers, $mode, null, null, null, false, false, $lastException->getMessage());
                            continue;
                        }

                        if (! $response->successful()) {
                            $status = $response->status();
                            $lastException = new RuntimeException("IXC respondeu com HTTP {$status}.");
                            $this->logDebugAttempt($company, $resource, $url, $normalizedMethod, $params, $headers, $mode, $status, (string) $response->body(), null, false, false, $lastException->getMessage());
                            if ($status >= 500 && $i + 1 < $attemptsPerMode) {
                                continue;
                            }
                            break 2;
                        }

                        $json = $response->json();
                        if (! is_array($json)) {
                            $lastException = new RuntimeException('Resposta inválida da API IXC.');
                            $this->logDebugAttempt($company, $resource, $url, $normalizedMethod, $params, $headers, $mode, $response->status(), (string) $response->body(), null, false, false, $lastException->getMessage());
                            break 2;
                        }

                        if ($this->isProviderErrorPayload($json)) {
                            $providerError = $this->extractProviderErrorMessage($json);
                            $shouldTryTokenFallback = $mode === 'listar'
                                && in_array('token', $requestModes, true)
                                && $modeIndex < (int) array_search('token', $requestModes, true);

                            $this->logDebugAttempt(
                                $company,
                                $resource,
                                $url,
                                $normalizedMethod,
                                $params,
                                $headers,
                                $mode,
                                $response->status(),
                                (string) $response->body(),
                                $json,
                                $shouldTryTokenFallback,
                                $shouldTryTokenFallback,
                                $providerError
                            );

                            if ($shouldTryTokenFallback) {
                                continue 2;
                            }

                            $lastException = new RuntimeException($providerError);
                            break 2;
                        }

                        $listSummary = $this->summarizeListExtraction($json);
                        $shouldFallback = $this->shouldFallbackToTokenMode($requestModes, $modeIndex, $mode, $listSummary);
                        $this->logDebugAttempt($company, $resource, $url, $normalizedMethod, $params, $headers, $mode, $response->status(), (string) $response->body(), $json, $shouldFallback, $shouldFallback, null, $listSummary['items_count'], $listSummary['total']);
                        if ($shouldFallback) {
                            continue 2;
                        }

                        $this->registerSuccess(
                            $company,
                            $resource,
                            $normalizedMethod,
                            $started,
                            $params,
                            $json,
                            ['ixcsoft_mode' => $mode, 'fallback_used' => $modeIndex > 0]
                        );
                        Cache::forget($this->breakerFailuresKey((int) $company->id, $resource, $normalizedMethod));
                        Cache::forget($breakerState);
                        return $json;
                    }
                }
            } finally {
            }

            $failureException = $lastException ?? new RuntimeException('Erro desconhecido na consulta IXC.');
            $this->registerFailure($company, $resource, $normalizedMethod, $failureException->getMessage(), $params, $started);
            if ($this->shouldTripBreakerForError($failureException->getMessage())) {
                $this->tripBreakerIfNeeded((int) $company->id, $resource, $normalizedMethod, $breakerState);
            }
            throw $failureException;
        };

        if ($useCache) {
            return Cache::remember($cacheKey, now()->addSeconds(30), $executor);
        }

        return $executor();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{status:int, body:string, content_type:string}
     */
    public function requestBinary(Company $company, string $resource, array $params = []): array
    {
        if (! $company->hasIxcIntegration()) {
            throw new RuntimeException('Integração IXC não está habilitada para esta empresa.');
        }

        $baseUrl = rtrim((string) ($company->ixc_base_url ?? ''), '/');
        $token = trim((string) ($company->ixc_api_token ?? ''));
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Credenciais IXC incompletas para esta empresa.');
        }
        if (! IxcUrlGuard::isSafeBaseUrl($baseUrl, (bool) config('ixc.allow_private_hosts', false))) {
            throw new RuntimeException('URL base da IXC inválida ou não permitida.');
        }

        $normalizedMethod = 'get';
        $breakerState = $this->breakerStateKey((int) $company->id, $resource, $normalizedMethod);
        if (Cache::get($breakerState) === 'open') {
            $this->registerBreakerOpen($company, $resource, $normalizedMethod);
            throw new RuntimeException('Integração IXC temporariamente indisponível para esta empresa. Tente novamente em instantes.');
        }
        $started = microtime(true);

        try {
            $response = Http::timeout(max(5, min(60, (int) ($company->ixc_timeout_seconds ?? 15))))
                ->withOptions(['verify' => ! (bool) $company->ixc_self_signed])
                ->withHeaders([
                    'ixcsoft' => $token,
                    'Authorization' => 'Basic ' . base64_encode($token),
                ])
                ->get($baseUrl . '/' . ltrim($resource, '/'), $params);
        } catch (ConnectionException) {
            $this->registerFailure($company, $resource, $normalizedMethod, 'Falha de conexão com a API IXC.', $params, $started);
            if ($this->shouldTripBreakerForError('Falha de conexão com a API IXC.')) {
                $this->tripBreakerIfNeeded((int) $company->id, $resource, $normalizedMethod, $breakerState);
            }
            throw new RuntimeException('Falha de conexão com a API IXC.');
        } catch (\Throwable) {
            $this->registerFailure($company, $resource, $normalizedMethod, 'Erro inesperado ao consultar a API IXC.', $params, $started);
            if ($this->shouldTripBreakerForError('Erro inesperado ao consultar a API IXC.')) {
                $this->tripBreakerIfNeeded((int) $company->id, $resource, $normalizedMethod, $breakerState);
            }
            throw new RuntimeException('Erro inesperado ao consultar a API IXC.');
        }

        if (! $response->successful()) {
            $message = "IXC respondeu com HTTP {$response->status()}.";
            $this->registerFailure($company, $resource, $normalizedMethod, $message, $params, $started);
            if ($this->shouldTripBreakerForError($message)) {
                $this->tripBreakerIfNeeded((int) $company->id, $resource, $normalizedMethod, $breakerState);
            }
            throw new RuntimeException($message);
        }

        $this->registerSuccess($company, $resource, $normalizedMethod, $started, $params);
        Cache::forget($this->breakerFailuresKey((int) $company->id, $resource, $normalizedMethod));
        Cache::forget($breakerState);

        return [
            'status' => $response->status(),
            'body' => (string) $response->body(),
            'content_type' => (string) $response->header('Content-Type', 'application/octet-stream'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{items: array<int, array<string,mixed>>, total: int, page: int, per_page: int}
     */
    public function normalizeList(array $payload, int $page, int $perPage): array
    {
        $items = $this->extractItems($payload);
        $total = $this->extractTotal($payload, $items);

        return [
            'items' => $items,
            'total' => $total,
            'page' => max(1, $page),
            'per_page' => max(1, $perPage),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string,mixed>>
     */
    private function extractItems(array $payload): array
    {
        $candidates = [
            $payload['registros'] ?? null,
            $payload['records'] ?? null,
            $payload['rows'] ?? null,
            $payload['items'] ?? null,
            $payload['data'] ?? null,
            $payload['dados'] ?? null,
            $payload['response']['registros'] ?? null,
            $payload['response']['items'] ?? null,
            $payload['result']['registros'] ?? null,
            $payload['result']['items'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $rows = $this->candidateToRows($candidate);
            if ($rows !== null) {
                return $rows;
            }
        }

        $fallbackRows = $this->candidateToRows($payload);
        if ($fallbackRows !== null) {
            return $fallbackRows;
        }

        return [];
    }

    /**
     * @param  mixed  $candidate
     * @return array<int, array<string,mixed>>|null
     */
    private function candidateToRows(mixed $candidate): ?array
    {
        if (is_string($candidate)) {
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $candidate = $decoded;
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }

        if (! is_array($candidate)) {
            return null;
        }

        foreach (['registros', 'records', 'rows', 'items', 'data', 'dados'] as $key) {
            if (array_key_exists($key, $candidate)) {
                return $this->candidateToRows($candidate[$key]);
            }
        }

        if (array_is_list($candidate)) {
            return array_values(array_filter($candidate, 'is_array'));
        }

        $rows = [];
        foreach ($candidate as $value) {
            if (! is_array($value)) {
                return null;
            }
            $rows[] = $value;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string,mixed>>  $items
     */
    private function extractTotal(array $payload, array $items): int
    {
        $candidates = [
            $payload['total'] ?? null,
            $payload['totalRecords'] ?? null,
            $payload['total_records'] ?? null,
            $payload['recordsTotal'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return max(0, (int) $candidate);
            }
        }

        return count($items);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function buildCacheKey(int $companyId, string $resource, array $params, string $method = 'get'): string
    {
        ksort($params);
        return 'ixc:' . $companyId . ':' . $method . ':' . $resource . ':' . md5(json_encode($params));
    }

    private function breakerFailuresKey(int $companyId, string $resource, string $method): string
    {
        return "ixc:breaker:failures:{$companyId}:{$method}:{$resource}";
    }

    private function breakerStateKey(int $companyId, string $resource, string $method): string
    {
        return "ixc:breaker:state:{$companyId}:{$method}:{$resource}";
    }

    private function tripBreakerIfNeeded(int $companyId, string $resource, string $method, string $breakerStateKey): void
    {
        $failuresKey = $this->breakerFailuresKey($companyId, $resource, $method);
        $count = (int) Cache::increment($failuresKey);
        if ($count === 1) {
            Cache::put($failuresKey, 1, now()->addSeconds(self::BREAKER_WINDOW_SECONDS));
        }

        if ($count >= self::BREAKER_THRESHOLD) {
            Cache::put($breakerStateKey, 'open', now()->addSeconds(self::BREAKER_OPEN_SECONDS));
            Log::warning('ixc.breaker.opened', [
                'company_id' => $companyId,
                'resource' => $resource,
                'method' => $method,
                'failure_count' => $count,
                'open_seconds' => self::BREAKER_OPEN_SECONDS,
            ]);
            $this->logOperationalMetric('breaker_open_total', [
                'company_id' => $companyId,
                'resource' => $resource,
                'method' => $method,
                'value' => 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function registerSuccess(
        Company $company,
        string $resource,
        string $method,
        float $started,
        array $params,
        ?array $payload = null,
        array $context = []
    ): void
    {
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        Log::info('ixc.request.ok', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'method' => $method,
            'duration_ms' => $durationMs,
            'params' => $this->sanitizeParamsForLogs($params),
            'payload_summary' => $payload !== null ? $this->summarizePayloadForLogs($payload) : null,
            'context' => $context,
        ]);
        $this->logOperationalMetric('request_latency_ms', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'method' => $method,
            'status' => 'ok',
            'value' => $durationMs,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function registerFailure(Company $company, string $resource, string $method, string $error, array $params, float $started): void
    {
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $status = $this->extractHttpStatusFromMessage($error);
        $errorType = $this->classifyErrorType($error, $status);

        Log::warning('ixc.request.fail', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'method' => $method,
            'duration_ms' => $durationMs,
            'error' => $error,
            'error_type' => $errorType,
            'http_status' => $status,
            'params' => $this->sanitizeParamsForLogs($params),
        ]);
        $this->logOperationalMetric('request_error_total', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'method' => $method,
            'value' => 1,
            'error_type' => $errorType,
            'http_status' => $status,
            'duration_ms' => $durationMs,
        ]);
    }

    private function registerBreakerOpen(Company $company, string $resource, string $method): void
    {
        Log::warning('ixc.breaker.blocked_request', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'method' => $method,
        ]);
        $this->logOperationalMetric('breaker_blocked_total', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'method' => $method,
            'value' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logOperationalMetric(string $metric, array $context): void
    {
        Log::info('ixc.metric', array_merge(['metric' => $metric], $context));
    }

    private function extractHttpStatusFromMessage(string $error): ?int
    {
        if (preg_match('/http\\s+(\\d{3})/i', $error, $matches) !== 1) {
            return null;
        }

        $status = (int) ($matches[1] ?? 0);
        return $status > 0 ? $status : null;
    }

    private function classifyErrorType(string $error, ?int $status): string
    {
        $normalized = mb_strtolower(trim($error));
        if (str_contains($normalized, 'falha de conex')) {
            return 'connection';
        }
        if (str_contains($normalized, 'erro inesperado')) {
            return 'unexpected';
        }
        if (str_contains($normalized, 'resposta inv')) {
            return 'invalid_response';
        }
        if ($status !== null) {
            if ($status >= 500) {
                return 'http_5xx';
            }
            if ($status >= 400) {
                return 'http_4xx';
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sanitizeParamsForLogs(array $params): array
    {
        $sensitiveKeys = ['token', 'authorization', 'linha_digitavel', 'email', 'phone', 'celular', 'sms'];
        $sanitized = [];
        foreach ($params as $key => $value) {
            $keyText = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($keyText, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $sanitized[$key] = '***';
                continue;
            }
            $sanitized[$key] = is_scalar($value) ? $value : '[complex]';
        }
        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function summarizePayloadForLogs(array $payload): array
    {
        $summary = [
            'keys' => array_slice(array_keys($payload), 0, 12),
        ];

        $registros = $payload['registros'] ?? $payload['items'] ?? $payload['rows'] ?? $payload['records'] ?? null;
        $summary['registros_type'] = gettype($registros);
        if (is_array($registros)) {
            $summary['registros_count'] = count($registros);
        } elseif (is_string($registros)) {
            $trimmed = trim($registros);
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                $summary['registros_json_decode_ok'] = is_array($decoded);
                $summary['registros_decoded_count'] = is_array($decoded) ? count($decoded) : 0;
            }
        }

        foreach (['total', 'total_records', 'totalRecords', 'recordsTotal'] as $totalKey) {
            if (array_key_exists($totalKey, $payload)) {
                $summary['total_key'] = $totalKey;
                $summary['total_value'] = $payload[$totalKey];
                break;
            }
        }

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    private function resolveRequestModes(string $resource, string $normalizedMethod): array
    {
        if (! $this->shouldUseListMode($resource, $normalizedMethod)) {
            return ['token'];
        }

        return ['listar', 'token'];
    }

    private function shouldUseListMode(string $resource, string $normalizedMethod): bool
    {
        if ($normalizedMethod !== 'get') {
            return false;
        }

        $resourceNormalized = strtolower(trim($resource));
        return in_array($resourceNormalized, ['cliente', 'fn_areceber'], true);
    }

    /**
     * @return array{ixcsoft:string,Authorization:string}
     */
    private function buildRequestHeaders(string $token, string $mode): array
    {
        return [
            'ixcsoft' => $mode === 'listar' ? 'listar' : $token,
            'Authorization' => 'Basic ' . base64_encode($token),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{items_count:int,total:int}
     */
    private function summarizeListExtraction(array $payload): array
    {
        $list = $this->normalizeList($payload, 1, 1);
        return [
            'items_count' => count($list['items']),
            'total' => (int) ($list['total'] ?? 0),
        ];
    }

    /**
     * @param  array<int, string>  $requestModes
     * @param  array{items_count:int,total:int}  $listSummary
     */
    private function shouldFallbackToTokenMode(array $requestModes, int $modeIndex, string $mode, array $listSummary): bool
    {
        if ($mode !== 'listar') {
            return false;
        }

        if (! in_array('token', $requestModes, true)) {
            return false;
        }

        if ($modeIndex >= array_search('token', $requestModes, true)) {
            return false;
        }

        return $listSummary['items_count'] === 0 && $listSummary['total'] <= 0;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{ixcsoft:string,Authorization:string}  $headers
     * @param  array<string, mixed>|null  $json
     */
    private function logDebugAttempt(
        Company $company,
        string $resource,
        string $url,
        string $method,
        array $params,
        array $headers,
        string $mode,
        ?int $status,
        ?string $rawBody,
        ?array $json,
        bool $fallbackTriggered,
        bool $fallbackEligible,
        ?string $error,
        ?int $itemsCount = null,
        ?int $total = null,
    ): void {
        if (! (bool) config('ixc.debug_log', false)) {
            return;
        }

        $rawBodyText = trim((string) ($rawBody ?? ''));
        if (mb_strlen($rawBodyText) > self::DEBUG_BODY_TRUNCATE) {
            $rawBodyText = mb_substr($rawBodyText, 0, self::DEBUG_BODY_TRUNCATE) . '... [truncated]';
        }

        Log::debug('ixc.request.debug', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'url' => $url,
            'method' => $method,
            'status' => $status,
            'ixcsoft_mode' => $mode,
            'fallback_eligible' => $fallbackEligible,
            'fallback_triggered' => $fallbackTriggered,
            'items_extracted' => $itemsCount,
            'total_extracted' => $total,
            'params' => $this->sanitizeParamsForLogs($params),
            'headers' => $this->sanitizeHeadersForLogs($headers),
            'raw_body' => $rawBodyText,
            'json_summary' => $json !== null ? $this->summarizePayloadForLogs($json) : null,
            'error' => $error,
        ]);
    }

    /**
     * @param  array{ixcsoft:string,Authorization:string}  $headers
     * @return array<string, string>
     */
    private function sanitizeHeadersForLogs(array $headers): array
    {
        $ixcsoft = (string) ($headers['ixcsoft'] ?? '');
        $authorization = (string) ($headers['Authorization'] ?? '');

        return [
            'ixcsoft' => $ixcsoft === 'listar' ? 'listar' : $this->maskSecret($ixcsoft),
            'Authorization' => $authorization === '' ? '' : 'Basic ***',
        ];
    }

    private function maskSecret(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) <= 6) {
            return '***';
        }

        return mb_substr($trimmed, 0, 3) . '***' . mb_substr($trimmed, -2);
    }

    private function shouldTripBreakerForError(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return true;
        }

        if (str_contains($normalized, 'falha de conexão') || str_contains($normalized, 'falha de conexao')) {
            return true;
        }
        if (str_contains($normalized, 'erro inesperado')) {
            return true;
        }

        if (preg_match('/http\s+(\d{3})/i', $message, $matches) === 1) {
            $status = (int) ($matches[1] ?? 0);
            return $status >= 500;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isProviderErrorPayload(array $payload): bool
    {
        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        if ($type === 'error') {
            return true;
        }

        return array_key_exists('error', $payload) && ! empty($payload['error']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractProviderErrorMessage(array $payload): string
    {
        $message = trim((string) ($payload['message'] ?? $payload['mensagem'] ?? $payload['error'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return 'API IXC retornou erro de recurso/operação.';
    }
}


