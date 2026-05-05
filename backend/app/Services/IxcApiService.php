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

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(Company $company, string $resource, array $params, string $method = 'get'): array
    {
        if (! $company->hasIxcIntegration()) {
            throw new RuntimeException('Integracao IXC nao esta habilitada para esta empresa.');
        }

        $baseUrl = rtrim((string) ($company->ixc_base_url ?? ''), '/');
        $token = trim((string) ($company->ixc_api_token ?? ''));
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Credenciais IXC incompletas para esta empresa.');
        }
        if (! IxcUrlGuard::isSafeBaseUrl($baseUrl, (bool) config('ixc.allow_private_hosts', false))) {
            throw new RuntimeException('URL base da IXC invalida ou nao permitida.');
        }

        $normalizedMethod = strtolower(trim($method));
        $useCache = $normalizedMethod === 'get';
        $cacheKey = $this->buildCacheKey((int) $company->id, $resource, $params, $normalizedMethod);
        $breakerState = $this->breakerStateKey((int) $company->id, $resource, $normalizedMethod);
        if (Cache::get($breakerState) === 'open') {
            throw new RuntimeException('Integracao IXC temporariamente indisponivel para esta empresa. Tente novamente em instantes.');
        }

        $executor = function () use ($baseUrl, $token, $resource, $params, $company, $normalizedMethod, $breakerState): array {
            $attempts = $normalizedMethod === 'get' ? 2 : 1;
            $lastException = null;
            $started = microtime(true);
            try {
                for ($i = 0; $i < $attempts; $i++) {
                    try {
                        $client = Http::timeout(max(5, min(60, (int) ($company->ixc_timeout_seconds ?? 15))))
                            ->acceptJson()
                            ->withOptions(['verify' => ! (bool) $company->ixc_self_signed])
                            ->withHeaders([
                                'ixcsoft' => $token,
                                'Authorization' => 'Basic ' . base64_encode($token),
                            ]);
                        $url = $baseUrl . '/' . ltrim($resource, '/');

                        $response = match ($normalizedMethod) {
                            'post' => $client->post($url, $params),
                            default => $client->get($url, $params),
                        };
                    } catch (ConnectionException) {
                        $lastException = new RuntimeException('Falha de conexao com a API IXC.');
                        continue;
                    } catch (\Throwable) {
                        $lastException = new RuntimeException('Erro inesperado ao consultar a API IXC.');
                        continue;
                    }

                    if (! $response->successful()) {
                        $status = $response->status();
                        $lastException = new RuntimeException("IXC respondeu com HTTP {$status}.");
                        if ($status >= 500 && $i + 1 < $attempts) {
                            continue;
                        }
                        break;
                    }

                    $json = $response->json();
                    if (! is_array($json)) {
                        $lastException = new RuntimeException('Resposta invalida da API IXC.');
                        break;
                    }

                    $this->registerSuccess($company, $resource, $normalizedMethod, $started, $params);
                    Cache::forget($this->breakerFailuresKey((int) $company->id, $resource, $normalizedMethod));
                    Cache::forget($breakerState);
                    return $json;
                }
            } finally {
            }

            $failureException = $lastException ?? new RuntimeException('Erro desconhecido na consulta IXC.');
            $this->registerFailure($company, $resource, $normalizedMethod, $failureException->getMessage(), $params, $started);
            $this->tripBreakerIfNeeded((int) $company->id, $resource, $normalizedMethod, $breakerState);
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
            throw new RuntimeException('Integracao IXC nao esta habilitada para esta empresa.');
        }

        $baseUrl = rtrim((string) ($company->ixc_base_url ?? ''), '/');
        $token = trim((string) ($company->ixc_api_token ?? ''));
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Credenciais IXC incompletas para esta empresa.');
        }
        if (! IxcUrlGuard::isSafeBaseUrl($baseUrl, (bool) config('ixc.allow_private_hosts', false))) {
            throw new RuntimeException('URL base da IXC invalida ou nao permitida.');
        }

        $normalizedMethod = 'get';
        $breakerState = $this->breakerStateKey((int) $company->id, $resource, $normalizedMethod);
        if (Cache::get($breakerState) === 'open') {
            throw new RuntimeException('Integracao IXC temporariamente indisponivel para esta empresa. Tente novamente em instantes.');
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
            $this->registerFailure($company, $resource, $normalizedMethod, 'Falha de conexao com a API IXC.', $params, $started);
            $this->tripBreakerIfNeeded((int) $company->id, $resource, $normalizedMethod, $breakerState);
            throw new RuntimeException('Falha de conexao com a API IXC.');
        } catch (\Throwable) {
            $this->registerFailure($company, $resource, $normalizedMethod, 'Erro inesperado ao consultar a API IXC.', $params, $started);
            $this->tripBreakerIfNeeded((int) $company->id, $resource, $normalizedMethod, $breakerState);
            throw new RuntimeException('Erro inesperado ao consultar a API IXC.');
        }

        if (! $response->successful()) {
            $message = "IXC respondeu com HTTP {$response->status()}.";
            $this->registerFailure($company, $resource, $normalizedMethod, $message, $params, $started);
            $this->tripBreakerIfNeeded((int) $company->id, $resource, $normalizedMethod, $breakerState);
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
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        return [];
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
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function registerSuccess(Company $company, string $resource, string $method, float $started, array $params): void
    {
        Log::info('ixc.request.ok', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'method' => $method,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'params' => $this->sanitizeParamsForLogs($params),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function registerFailure(Company $company, string $resource, string $method, string $error, array $params, float $started): void
    {
        Log::warning('ixc.request.fail', [
            'company_id' => (int) $company->id,
            'resource' => $resource,
            'method' => $method,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'error' => $error,
            'params' => $this->sanitizeParamsForLogs($params),
        ]);
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
}
